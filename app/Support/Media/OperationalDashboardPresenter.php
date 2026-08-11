<?php

namespace App\Support\Media;

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\Exceptions\MediaConfigurationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class OperationalDashboardPresenter
{
    private const WARNING_LIMIT = 5;

    private const EXPIRY_WARNING_HOURS = 24;

    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MediaDiskHealthChecker $diskHealthChecker,
    ) {}

    /**
     * @return array{
     *     scope: 'personal'|'installation',
     *     generated_at: string,
     *     expiry_warning_cutoff: string,
     *     counts: array{active: int, paused: int, processing: int, failed: int, expiring: int},
     *     warnings: array{failed: list<array<string, mixed>>, expiring: list<array<string, mixed>>}
     * }
     */
    public function uploadOverview(User $viewer): array
    {
        $generatedAt = now();
        $expiryWarningCutoff = $generatedAt->addHours(self::EXPIRY_WARNING_HOURS);
        $installationWide = $viewer->isAdministrator();
        $scopedUploads = Upload::query()
            ->when(
                ! $installationWide,
                fn (Builder $query): Builder => $query->whereBelongsTo($viewer),
            );

        $aggregate = (clone $scopedUploads)
            ->toBase()
            ->selectRaw(
                <<<'SQL'
                count(case when status in (?, ?) then 1 end) as active,
                count(case when status = ? then 1 end) as paused,
                count(case when status = ? then 1 end) as processing,
                count(case when status = ? then 1 end) as failed,
                count(case when status in (?, ?, ?) and expires_at is not null and expires_at <= ? then 1 end) as expiring
                SQL,
                [
                    UploadStatus::Pending->value,
                    UploadStatus::Uploading->value,
                    UploadStatus::Paused->value,
                    UploadStatus::Processing->value,
                    UploadStatus::Failed->value,
                    UploadStatus::Pending->value,
                    UploadStatus::Uploading->value,
                    UploadStatus::Paused->value,
                    $expiryWarningCutoff,
                ],
            )
            ->first();

        $failedWarnings = (clone $scopedUploads)
            ->select([
                'id',
                'uuid',
                'user_id',
                'original_filename',
                'status',
                'error_detail',
                'failed_at',
            ])
            ->when(
                $installationWide,
                fn (Builder $query): Builder => $query->with('user:id,name'),
            )
            ->where('status', UploadStatus::Failed)
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit(self::WARNING_LIMIT)
            ->get()
            ->map(fn (Upload $upload): array => [
                ...$this->warningIdentity($upload, $viewer, $installationWide),
                'failure_detail' => $this->safeFailureDetail($upload->error_detail),
            ])
            ->values()
            ->all();

        $failedWarnings = array_values($failedWarnings);

        $expiringWarnings = (clone $scopedUploads)
            ->select([
                'id',
                'uuid',
                'user_id',
                'original_filename',
                'status',
                'declared_size',
                'confirmed_offset',
                'expires_at',
            ])
            ->when(
                $installationWide,
                fn (Builder $query): Builder => $query->with('user:id,name'),
            )
            ->whereIn('status', [
                UploadStatus::Pending,
                UploadStatus::Uploading,
                UploadStatus::Paused,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $expiryWarningCutoff)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::WARNING_LIMIT)
            ->get()
            ->map(fn (Upload $upload): array => [
                ...$this->warningIdentity($upload, $viewer, $installationWide),
                'confirmed_bytes' => $upload->confirmed_offset,
                'declared_bytes' => $upload->declared_size,
                'progress_percentage' => $this->progressPercentage($upload),
                'expires_at' => $this->expiryDeadline($upload),
            ])
            ->values()
            ->all();

        $expiringWarnings = array_values($expiringWarnings);

        return [
            'scope' => $installationWide ? 'installation' : 'personal',
            'generated_at' => $generatedAt->toIso8601String(),
            'expiry_warning_cutoff' => $expiryWarningCutoff->toIso8601String(),
            'counts' => [
                'active' => $this->aggregateCount($aggregate->active ?? null),
                'paused' => $this->aggregateCount($aggregate->paused ?? null),
                'processing' => $this->aggregateCount($aggregate->processing ?? null),
                'failed' => $this->aggregateCount($aggregate->failed ?? null),
                'expiring' => $this->aggregateCount($aggregate->expiring ?? null),
            ],
            'warnings' => [
                'failed' => $failedWarnings,
                'expiring' => $expiringWarnings,
            ],
        ];
    }

    /**
     * @return array{
     *     status: 'available'|'unavailable',
     *     checked_at: string,
     *     message: string|null,
     *     disks: list<array<string, mixed>>
     * }
     */
    public function diskOverview(): array
    {
        $checkedAt = now()->toIso8601String();

        try {
            $disks = array_map(
                fn (ConfiguredMediaDisk $disk): array => $this->diskHealthChecker
                    ->check($disk, $this->diskRegistry->requiresMountpoint())
                    ->toArray(),
                $this->diskRegistry->all(),
            );
        } catch (MediaConfigurationException) {
            return [
                'status' => 'unavailable',
                'checked_at' => $checkedAt,
                'message' => 'Media disk configuration is unavailable.',
                'disks' => [],
            ];
        }

        return [
            'status' => 'available',
            'checked_at' => $checkedAt,
            'message' => null,
            'disks' => $disks,
        ];
    }

    /**
     * @return array{uuid: string, original_filename: string, status: string, can_open_recovery: bool, owner_name?: string}
     */
    private function warningIdentity(Upload $upload, User $viewer, bool $installationWide): array
    {
        $identity = [
            'uuid' => $upload->uuid,
            'original_filename' => $upload->original_filename,
            'status' => $upload->status->value,
            'can_open_recovery' => $upload->user_id === $viewer->getKey(),
        ];

        if ($installationWide) {
            $owner = $upload->getRelation('user');
            $identity['owner_name'] = $owner instanceof User ? $owner->name : 'Unknown owner';
        }

        return $identity;
    }

    private function safeFailureDetail(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        $safeDetail = Str::of($detail)
            ->replaceMatches('/[\x00-\x1F\x7F]/u', ' ')
            ->squish()
            ->limit(500, '')
            ->toString();

        if ($safeDetail === '') {
            return null;
        }

        $containsAbsolutePath = preg_match('~(?:^|\s)(?:/\S+|[A-Za-z]:\\\\\S+)~u', $safeDetail) === 1;
        $containsSecret = preg_match('/\b[a-f0-9]{32,}\b/i', $safeDetail) === 1
            || preg_match('/\b(?:token|secret|password)\s*[:=]\s*\S+/i', $safeDetail) === 1;

        return $containsAbsolutePath || $containsSecret
            ? 'The upload failed during processing.'
            : $safeDetail;
    }

    private function aggregateCount(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    private function progressPercentage(Upload $upload): int
    {
        if ($upload->declared_size <= 0) {
            return 0;
        }

        return min(
            (int) floor(($upload->confirmed_offset / $upload->declared_size) * 100),
            100,
        );
    }

    private function expiryDeadline(Upload $upload): string
    {
        return $upload->expires_at?->toIso8601String()
            ?? throw new \LogicException('An expiring dashboard upload must have a deadline.');
    }
}
