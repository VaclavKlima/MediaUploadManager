<?php

namespace App\Models;

use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\ValueObjects\ByteCount;
use App\ValueObjects\LocalFileFingerprint;
use App\ValueObjects\RelativeMediaPath;
use App\ValueObjects\TokenHash;
use Carbon\CarbonInterface;
use Database\Factories\UploadFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string|null $idempotency_key
 * @property int|null $media_item_id
 * @property int|null $series_episode_id
 * @property int|null $series_upload_batch_id
 * @property int|null $batch_position
 * @property UploadStatus $status
 * @property string $disk_id
 * @property MediaRootKind $root_kind
 * @property string $target_relative_path
 * @property string $staging_relative_path
 * @property string $original_filename
 * @property string $extension
 * @property int $declared_size
 * @property int $confirmed_offset
 * @property int|null $last_modified_milliseconds
 * @property string $fingerprint_first_sha256
 * @property string $fingerprint_last_sha256
 * @property string|null $tus_resource_id
 * @property CarbonInterface|null $tus_creation_claimed_at
 * @property CarbonInterface|null $tus_created_at
 * @property string|null $token_hash
 * @property list<string>|null $token_abilities
 * @property CarbonInterface|null $token_expires_at
 * @property CarbonInterface|null $last_activity_at
 * @property CarbonInterface|null $expires_at
 * @property string|null $error_code
 * @property string|null $error_detail
 * @property array<string, mixed>|null $processing_claim
 * @property CarbonInterface|null $finalization_started_at
 * @property int|null $replaces_media_file_id
 * @property CarbonInterface|null $replacement_confirmed_at
 * @property CarbonInterface|null $uploading_at
 * @property CarbonInterface|null $paused_at
 * @property CarbonInterface|null $processing_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $expired_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'uuid',
    'user_id',
    'idempotency_key',
    'media_item_id',
    'series_episode_id',
    'series_upload_batch_id',
    'batch_position',
    'status',
    'disk_id',
    'root_kind',
    'target_relative_path',
    'staging_relative_path',
    'original_filename',
    'extension',
    'declared_size',
    'confirmed_offset',
    'last_modified_milliseconds',
    'fingerprint_first_sha256',
    'fingerprint_last_sha256',
    'tus_resource_id',
    'tus_creation_claimed_at',
    'tus_created_at',
    'token_hash',
    'token_abilities',
    'token_expires_at',
    'last_activity_at',
    'expires_at',
    'error_code',
    'error_detail',
    'processing_claim',
    'finalization_started_at',
    'replaces_media_file_id',
    'replacement_confirmed_at',
    'uploading_at',
    'paused_at',
    'processing_at',
    'completed_at',
    'failed_at',
    'cancelled_at',
    'expired_at',
])]
#[Hidden(['token_hash', 'processing_claim'])]
class Upload extends Model
{
    /** @use HasFactory<UploadFactory> */
    use HasFactory;

    /** @var list<string> */
    private const IMMUTABLE_ATTRIBUTES = [
        'uuid',
        'user_id',
        'idempotency_key',
        'media_item_id',
        'series_episode_id',
        'series_upload_batch_id',
        'batch_position',
        'replaces_media_file_id',
        'replacement_confirmed_at',
        'disk_id',
        'root_kind',
        'target_relative_path',
        'staging_relative_path',
        'original_filename',
        'extension',
        'declared_size',
        'last_modified_milliseconds',
        'fingerprint_first_sha256',
        'fingerprint_last_sha256',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => UploadStatus::Pending->value,
        'root_kind' => MediaRootKind::Movies->value,
        'confirmed_offset' => 0,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MediaItem, $this> */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    /** @return BelongsTo<SeriesEpisode, $this> */
    public function seriesEpisode(): BelongsTo
    {
        return $this->belongsTo(SeriesEpisode::class);
    }

    /** @return BelongsTo<SeriesUploadBatch, $this> */
    public function seriesUploadBatch(): BelongsTo
    {
        return $this->belongsTo(SeriesUploadBatch::class);
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function replacesMediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'replaces_media_file_id');
    }

    /** @return HasOne<MediaFile, $this> */
    public function mediaFile(): HasOne
    {
        return $this->hasOne(MediaFile::class, 'source_upload_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function reservesCapacity(): bool
    {
        return $this->status->reservesCapacity();
    }

    public function reservedBytes(): ByteCount
    {
        if (! $this->reservesCapacity()) {
            return new ByteCount(0);
        }

        return (new ByteCount($this->declared_size))->remainingAfter($this->confirmed_offset);
    }

    public function confirmOffset(int|ByteCount $offset): self
    {
        $this->confirmed_offset = ByteCount::from($offset)->value;
        $this->save();

        return $this;
    }

    public function assignTusResourceId(string $resourceId): self
    {
        $this->tus_resource_id = $resourceId;
        $this->save();

        return $this;
    }

    protected static function booted(): void
    {
        static::creating(function (self $upload): void {
            $upload->uuid ??= (string) Str::uuid7();

            if ($upload->status !== UploadStatus::Pending) {
                throw new DomainException('A new upload must begin in the pending state.');
            }

            $upload->validateInvariantFields();
        });

        static::updating(function (self $upload): void {
            if ($upload->isDirty(self::IMMUTABLE_ATTRIBUTES)) {
                throw new DomainException('Upload admission attributes are immutable.');
            }

            if ($upload->isDirty('status')) {
                throw new DomainException('Upload status changes must use the transition action.');
            }

            if ($upload->getOriginal('tus_resource_id') !== null && $upload->isDirty('tus_resource_id')) {
                throw new DomainException('A tus resource identity is write-once.');
            }

            foreach (['tus_creation_claimed_at', 'tus_created_at', 'finalization_started_at'] as $writeOnceTimestamp) {
                if ($upload->getOriginal($writeOnceTimestamp) !== null && $upload->isDirty($writeOnceTimestamp)) {
                    throw new DomainException('Tus lifecycle timestamps are write-once.');
                }
            }

            if ($upload->getOriginal('processing_claim') !== null && $upload->isDirty('processing_claim')) {
                throw new DomainException('The upload processing claim is write-once.');
            }

            $upload->validateInvariantFields();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UploadStatus::class,
            'root_kind' => MediaRootKind::class,
            'token_abilities' => 'array',
            'processing_claim' => 'array',
            'finalization_started_at' => 'datetime',
            'tus_creation_claimed_at' => 'datetime',
            'tus_created_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'replacement_confirmed_at' => 'datetime',
            'uploading_at' => 'datetime',
            'paused_at' => 'datetime',
            'processing_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    private function validateInvariantFields(): void
    {
        if (($this->media_item_id === null) === ($this->series_episode_id === null)) {
            throw new DomainException('An upload must belong to exactly one Movie or Series episode.');
        }

        if (($this->root_kind === MediaRootKind::Movies) !== ($this->media_item_id !== null)) {
            throw new DomainException('The upload root kind must match its subject.');
        }

        if (($this->series_upload_batch_id === null) !== ($this->batch_position === null)
            || ($this->series_episode_id === null && $this->series_upload_batch_id !== null)
        ) {
            throw new DomainException('Only Series episode uploads may have an immutable batch position.');
        }

        if (! Str::isUuid($this->uuid, version: 7)) {
            throw new DomainException('An upload public identity must be a UUIDv7.');
        }

        if ($this->idempotency_key !== null && ! Str::isUuid($this->idempotency_key)) {
            throw new DomainException('An upload idempotency key must be a UUID.');
        }

        new RelativeMediaPath($this->target_relative_path);
        new RelativeMediaPath($this->staging_relative_path);

        $declaredSize = new ByteCount($this->declared_size);
        $confirmedOffset = new ByteCount($this->confirmed_offset);

        if ($confirmedOffset->value > $declaredSize->value) {
            throw new DomainException('A confirmed offset cannot exceed the declared size.');
        }

        $originalConfirmedOffset = $this->getRawOriginal('confirmed_offset');

        if (! is_int($originalConfirmedOffset) && ! is_string($originalConfirmedOffset)) {
            throw new DomainException('The persisted confirmed offset is invalid.');
        }

        if ($this->exists && $this->isDirty('confirmed_offset') && $confirmedOffset->value < (int) $originalConfirmedOffset) {
            throw new DomainException('A confirmed offset cannot move backwards.');
        }

        new LocalFileFingerprint(
            $declaredSize,
            $this->last_modified_milliseconds,
            $this->fingerprint_first_sha256,
            $this->fingerprint_last_sha256,
        );

        if ($this->original_filename === '' || Str::length($this->original_filename) > 255 || preg_match('#[/\\\\\x00-\x1F\x7F]#u', $this->original_filename) === 1) {
            throw new DomainException('An original filename must be a safe basename.');
        }

        if (preg_match('/\A[a-z0-9]{1,16}\z/', $this->extension) !== 1) {
            throw new DomainException('An upload extension must be normalized lowercase ASCII.');
        }

        if ($this->token_hash !== null) {
            TokenHash::fromHash($this->token_hash);
        }

        if ($this->tus_resource_id !== null && ($this->tus_resource_id === '' || Str::length($this->tus_resource_id) > 255)) {
            throw new DomainException('A tus resource identity must contain between 1 and 255 characters.');
        }

        if ($this->replaces_media_file_id !== null) {
            $replacementTargetMatchesSubject = MediaFile::query()
                ->whereKey($this->replaces_media_file_id)
                ->where('media_item_id', $this->media_item_id)
                ->where('series_episode_id', $this->series_episode_id)
                ->exists();
            $replacementTargetIsCurrent = $this->media_item_id !== null
                ? MediaItem::query()->whereKey($this->media_item_id)->where('current_media_file_id', $this->replaces_media_file_id)->exists()
                : SeriesEpisode::query()->whereKey($this->series_episode_id)->where('current_media_file_id', $this->replaces_media_file_id)->exists();
            $replacementMediaFileId = $this->exists
                ? MediaFile::query()->where('source_upload_id', $this->getKey())->value('id')
                : null;
            $replacementWasCommitted = is_int($replacementMediaFileId)
                && MediaFile::query()
                    ->whereKey($this->replaces_media_file_id)
                    ->where('replaced_by_media_file_id', $replacementMediaFileId)
                    ->exists()
                && ($this->media_item_id !== null
                    ? MediaItem::query()->whereKey($this->media_item_id)->where('current_media_file_id', $replacementMediaFileId)->exists()
                    : SeriesEpisode::query()->whereKey($this->series_episode_id)->where('current_media_file_id', $replacementMediaFileId)->exists());

            if (! $replacementTargetMatchesSubject
                || (! $replacementTargetIsCurrent && ! $replacementWasCommitted)
                || $this->replacement_confirmed_at === null
            ) {
                throw new DomainException('A replacement target must be the subject current primary and be explicitly confirmed.');
            }
        }
    }
}
