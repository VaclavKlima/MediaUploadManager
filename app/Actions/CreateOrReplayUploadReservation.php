<?php

namespace App\Actions;

use App\Enums\UploadStatus;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\CanonicalMoviePath;
use App\Support\Media\Exceptions\MediaConfigurationException;
use App\Support\Media\Exceptions\UploadAdmissionException;
use App\Support\Media\JellyfinMoviePathBuilder;
use App\Support\Media\MovieDiskTargetStatus;
use App\Support\Media\MovieUploadConflictChecker;
use App\Support\Media\UploadCapacityPlanner;
use App\Support\Media\UploadConfiguration;
use App\Support\Media\UploadReservationResult;
use App\Support\SecurityAudit;
use App\ValueObjects\TokenHash;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class CreateOrReplayUploadReservation
{
    public const ADMISSION_LOCK_NAME = 'upload-admission:ordinary';

    /** @var list<string> */
    public const TOKEN_ABILITIES = ['tus:create', 'tus:read', 'tus:write', 'tus:terminate'];

    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 2;

    public function __construct(
        private readonly JellyfinMoviePathBuilder $pathBuilder,
        private readonly UploadCapacityPlanner $capacityPlanner,
        private readonly UploadConfiguration $uploadConfiguration,
        private readonly CacheManager $cacheManager,
        private readonly MovieUploadConflictChecker $conflictChecker,
    ) {}

    /**
     * @param array{
     *     idempotency_key: string,
     *     filename: string,
     *     declared_size: int,
     *     last_modified_milliseconds?: int|null,
     *     fingerprint_first_sha256: string,
     *     fingerprint_last_sha256: string,
     *     disk_id: string,
     *     replaces_media_file_id?: int|null,
     *     replacement_confirmed?: bool
     * } $input
     */
    public function execute(User $user, MediaItem $mediaItem, array $input): UploadReservationResult
    {
        try {
            $repository = $this->cacheManager->store('database');

            if (! $repository instanceof Repository || ! $repository->getStore() instanceof LockProvider) {
                throw new UploadAdmissionException(
                    'upload_configuration_invalid',
                    'Upload configuration is unavailable.',
                    503,
                );
            }

            $result = $repository->getStore()
                ->lock(self::ADMISSION_LOCK_NAME, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn (): UploadReservationResult => DB::transaction(
                    fn (): UploadReservationResult => $this->admit($user, $mediaItem, $input),
                    attempts: 3,
                ));

            if (! $result instanceof UploadReservationResult) {
                throw new UploadAdmissionException(
                    'admission_unavailable',
                    'Upload admission is temporarily unavailable.',
                    503,
                );
            }

            if (! $result->idempotentReplay && $result->upload->replaces_media_file_id !== null) {
                SecurityAudit::mediaReplacementConfirmed($result->upload);
            }

            return $result;
        } catch (LockTimeoutException $exception) {
            throw new UploadAdmissionException(
                'admission_lock_timeout',
                'Upload admission is busy. Please try again.',
                503,
                $exception,
            );
        } catch (UploadAdmissionException|MediaConfigurationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UploadAdmissionException(
                'admission_unavailable',
                'Upload admission is temporarily unavailable.',
                503,
                $exception,
            );
        }
    }

    /**
     * @param array{
     *     idempotency_key: string,
     *     filename: string,
     *     declared_size: int,
     *     last_modified_milliseconds?: int|null,
     *     fingerprint_first_sha256: string,
     *     fingerprint_last_sha256: string,
     *     disk_id: string,
     *     replaces_media_file_id?: int|null,
     *     replacement_confirmed?: bool
     * } $input
     */
    private function admit(User $user, MediaItem $mediaItem, array $input): UploadReservationResult
    {
        $mediaItem = MediaItem::query()
            ->whereKey($mediaItem->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($mediaItem->deletion_requested_at !== null || $mediaItem->deletion_claim !== null) {
            throw new UploadAdmissionException(
                'movie_deletion_in_progress',
                'This movie has a confirmed permanent deletion in progress.',
                409,
            );
        }

        try {
            $path = $this->pathBuilder->build($mediaItem, $input['filename']);
        } catch (InvalidArgumentException $exception) {
            throw new UploadAdmissionException(
                'upload_request_invalid',
                'A reservation cannot be built from this movie and filename.',
                422,
                $exception,
            );
        }

        $idempotencyKey = Str::lower($input['idempotency_key']);
        $existingUpload = Upload::query()
            ->whereBelongsTo($user)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existingUpload !== null) {
            return $this->replay($user, $existingUpload, $mediaItem, $path, $input);
        }

        $capacityPlan = $this->capacityPlanner->plan($mediaItem, $path, $input['declared_size'], $user);
        $selectedDisk = $capacityPlan->disk($input['disk_id']);
        $replacementTargetId = $input['replaces_media_file_id'] ?? null;
        $replacementConfirmed = $input['replacement_confirmed'] ?? false;

        if ($selectedDisk === null || $selectedDisk->status === 'unavailable') {
            throw new UploadAdmissionException(
                'disk_unavailable',
                'The selected disk is unavailable.',
                409,
            );
        }

        if ($replacementTargetId !== null) {
            if (! $replacementConfirmed
                || ! $capacityPlan->canReplaceCurrentPrimary
                || $capacityPlan->replaceable?->id !== $replacementTargetId
                || $selectedDisk->status !== 'replaceable'
            ) {
                throw new UploadAdmissionException(
                    'replacement_conflict',
                    'The confirmed current primary is no longer safely replaceable.',
                    409,
                );
            }
        } elseif ($replacementConfirmed) {
            throw new UploadAdmissionException(
                'upload_request_invalid',
                'Replacement confirmation requires an exact current-primary identity.',
                422,
            );
        } elseif ($capacityPlan->blockers !== [] || $selectedDisk->status === 'conflict') {
            throw new UploadAdmissionException(
                'upload_conflict',
                'The movie destination changed and now conflicts with another item.',
                409,
            );
        }

        if (! $selectedDisk->eligible) {
            throw new UploadAdmissionException(
                'insufficient_capacity',
                'The selected disk no longer has enough reservable capacity.',
                409,
            );
        }

        $uuid = (string) Str::uuid7();
        [$plaintextToken, $tokenHash] = $this->issueToken();
        $now = now();
        $upload = Upload::query()->create([
            'uuid' => $uuid,
            'user_id' => $user->getKey(),
            'idempotency_key' => $idempotencyKey,
            'media_item_id' => $mediaItem->getKey(),
            'replaces_media_file_id' => $replacementTargetId,
            'replacement_confirmed_at' => $replacementTargetId === null ? null : $now,
            'status' => UploadStatus::Pending,
            'disk_id' => $selectedDisk->id,
            'target_relative_path' => $path->relativePath,
            'staging_relative_path' => '.media-upload-manager/incoming/'.$uuid.'.part',
            'original_filename' => $input['filename'],
            'extension' => $path->extension,
            'declared_size' => $input['declared_size'],
            'confirmed_offset' => 0,
            'last_modified_milliseconds' => $input['last_modified_milliseconds'] ?? null,
            'fingerprint_first_sha256' => $input['fingerprint_first_sha256'],
            'fingerprint_last_sha256' => $input['fingerprint_last_sha256'],
            'token_hash' => $tokenHash,
            'token_abilities' => self::TOKEN_ABILITIES,
            'token_expires_at' => $now->addSeconds($this->uploadConfiguration->tokenTtlSeconds),
            'last_activity_at' => $now,
            'expires_at' => $now->addSeconds($this->uploadConfiguration->inactivitySeconds),
        ]);

        return new UploadReservationResult($upload, $plaintextToken, false);
    }

    /**
     * @param array{
     *     filename: string,
     *     declared_size: int,
     *     last_modified_milliseconds?: int|null,
     *     fingerprint_first_sha256: string,
     *     fingerprint_last_sha256: string,
     *     disk_id: string,
     *     idempotency_key: string,
     *     replaces_media_file_id?: int|null,
     *     replacement_confirmed?: bool
     * } $input
     */
    private function replay(
        User $user,
        Upload $upload,
        MediaItem $mediaItem,
        CanonicalMoviePath $path,
        array $input,
    ): UploadReservationResult {
        $matches = $upload->media_item_id === $mediaItem->getKey()
            && $upload->disk_id === $input['disk_id']
            && $upload->original_filename === $input['filename']
            && $upload->declared_size === $input['declared_size']
            && $upload->last_modified_milliseconds === ($input['last_modified_milliseconds'] ?? null)
            && $upload->fingerprint_first_sha256 === $input['fingerprint_first_sha256']
            && $upload->fingerprint_last_sha256 === $input['fingerprint_last_sha256']
            && $upload->target_relative_path === $path->relativePath
            && $upload->extension === $path->extension
            && $upload->replaces_media_file_id === ($input['replaces_media_file_id'] ?? null)
            && ($upload->replacement_confirmed_at !== null) === ($input['replacement_confirmed'] ?? false);

        if (! $matches
            || $upload->status !== UploadStatus::Pending
            || $upload->expires_at === null
            || $upload->expires_at->lessThanOrEqualTo(now())
        ) {
            throw new UploadAdmissionException(
                'idempotency_conflict',
                'The idempotency key was already used for a different or inactive reservation.',
                409,
            );
        }

        if ($upload->replaces_media_file_id !== null) {
            $replacementReport = $this->conflictChecker->check(
                $mediaItem,
                $path,
                $user,
                $upload->id,
            );
            $selectedDisk = collect($replacementReport->disks)->firstWhere('id', $upload->disk_id);

            if (! $replacementReport->canReplaceCurrentPrimary
                || $replacementReport->replaceable?->id !== $upload->replaces_media_file_id
                || ! $selectedDisk instanceof MovieDiskTargetStatus
                || $selectedDisk->status !== 'replaceable'
            ) {
                throw new UploadAdmissionException(
                    'replacement_conflict',
                    'The confirmed current primary is no longer safely replaceable.',
                    409,
                );
            }
        }

        [$plaintextToken, $tokenHash] = $this->issueToken();
        $now = now();
        $upload->update([
            'token_hash' => $tokenHash,
            'token_abilities' => self::TOKEN_ABILITIES,
            'token_expires_at' => $now->addSeconds($this->uploadConfiguration->tokenTtlSeconds),
            'last_activity_at' => $now,
            'expires_at' => $now->addSeconds($this->uploadConfiguration->inactivitySeconds),
        ]);

        return new UploadReservationResult($upload->refresh(), $plaintextToken, true);
    }

    /** @return array{string, string} */
    private function issueToken(): array
    {
        $plaintextToken = bin2hex(random_bytes(32));

        return [$plaintextToken, TokenHash::fromPlaintext($plaintextToken)->value];
    }
}
