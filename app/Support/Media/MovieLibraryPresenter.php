<?php

namespace App\Support\Media;

use App\Enums\UploadStatus;
use App\Models\MediaItem;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class MovieLibraryPresenter
{
    public function __construct(
        private ConfiguredDiskRegistry $diskRegistry,
        private MovieTechnicalTagPresenter $technicalTagPresenter,
    ) {}

    /**
     * @param  array{search: string|null, status: string|null, sort: string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $query = MediaItem::query()
            ->select([
                'id',
                'tmdb_id',
                'imdb_id',
                'title',
                'original_title',
                'release_year',
                'poster_path',
                'current_media_file_id',
                'deletion_requested_at',
                'created_at',
            ])
            ->with([
                'currentMediaFile' => function (Relation $relation): void {
                    $relation->getQuery()->select([
                        'id',
                        'media_item_id',
                        'source_upload_id',
                        'imported_by_user_id',
                        'disk_id',
                        'relative_path',
                        'size_bytes',
                        'duration_milliseconds',
                        'video_metadata',
                        'audio_metadata',
                        'finalized_at',
                    ])
                        ->with([
                            'sourceUpload' => function (Relation $relation): void {
                                $relation->getQuery()
                                    ->select(['id', 'user_id', 'media_item_id', 'status'])
                                    ->with('user:id,name');
                            },
                            'importedBy:id,name',
                        ]);
                },
                'latestReidentification',
            ])
            ->withCount([
                'uploads',
                'uploads as active_uploads_count' => fn (Builder $query) => $query->whereIn(
                    'status',
                    UploadStatus::capacityReservingValues(),
                ),
                'uploads as failed_uploads_count' => fn (Builder $query) => $query->where('status', UploadStatus::Failed),
                'uploads as other_user_uploads_count' => fn (Builder $query) => $query->where('user_id', '!=', $actor->id),
            ]);

        $this->applySearch($query, $filters['search']);
        $this->applyStatus($query, $filters['status']);

        if ($filters['sort'] === 'title') {
            $query->orderBy('title')->orderByDesc('release_year')->orderBy('id');
        } else {
            $query->latest('created_at')->latest('id');
        }

        return $query
            ->paginate(48)
            ->withQueryString()
            ->through(fn (MediaItem $mediaItem): array => $this->present($mediaItem, $actor));
    }

    /** @return array<string, mixed> */
    public function presentMovie(MediaItem $mediaItem, User $actor): array
    {
        $mediaItem->loadMissing([
            'currentMediaFile.sourceUpload.user',
            'currentMediaFile.importedBy',
            'latestReidentification',
        ])->loadCount([
            'uploads',
            'uploads as active_uploads_count' => fn (Builder $query) => $query->whereIn(
                'status',
                UploadStatus::capacityReservingValues(),
            ),
            'uploads as failed_uploads_count' => fn (Builder $query) => $query->where('status', UploadStatus::Failed),
            'uploads as other_user_uploads_count' => fn (Builder $query) => $query->where('user_id', '!=', $actor->id),
        ]);

        return $this->present($mediaItem, $actor);
    }

    /** @param Builder<MediaItem> $query */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $query->where(function (Builder $query) use ($search): void {
            $query->whereLike('title', "%{$search}%")
                ->orWhereLike('original_title', "%{$search}%");

            if (preg_match('/\A[0-9]+\z/', $search) === 1) {
                $query->orWhere('tmdb_id', (int) $search);
            }

            if (preg_match('/\Att[0-9]{7,12}\z/i', $search) === 1) {
                $query->orWhere('imdb_id', mb_strtolower($search));
            }
        });
    }

    /** @param Builder<MediaItem> $query */
    private function applyStatus(Builder $query, ?string $status): void
    {
        if ($status === null) {
            return;
        }

        $activeMovieIds = Upload::query()
            ->select('media_item_id')
            ->whereIn('status', UploadStatus::capacityReservingValues());
        $failedMovieIds = Upload::query()
            ->select('media_item_id')
            ->where('status', UploadStatus::Failed);

        match ($status) {
            'deleting' => $query->whereNotNull('deletion_requested_at'),
            'failed' => $query
                ->whereNull('deletion_requested_at')
                ->whereIn('id', $failedMovieIds),
            'in_progress' => $query
                ->whereNull('deletion_requested_at')
                ->whereIn('id', $activeMovieIds)
                ->whereNotIn('id', $failedMovieIds),
            'available' => $query
                ->whereNull('deletion_requested_at')
                ->whereNotNull('current_media_file_id')
                ->whereNotIn('id', $activeMovieIds)
                ->whereNotIn('id', $failedMovieIds),
            'orphaned' => $query
                ->whereNull('deletion_requested_at')
                ->whereNull('current_media_file_id')
                ->whereNotIn('id', $activeMovieIds)
                ->whereNotIn('id', $failedMovieIds),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function present(MediaItem $mediaItem, User $actor): array
    {
        $currentMediaFile = $mediaItem->currentMediaFile;
        $sourceUpload = $currentMediaFile?->sourceUpload;
        $owner = $sourceUpload->user ?? $currentMediaFile?->importedBy;
        $latestReidentification = $mediaItem->latestReidentification;
        $activeUploads = $mediaItem->active_uploads_count;
        $failedUploads = $mediaItem->failed_uploads_count;
        $uploadCount = $mediaItem->uploads_count;
        $otherUserUploads = $mediaItem->other_user_uploads_count;
        $deleting = $mediaItem->deletion_requested_at !== null;
        $authorized = $actor->isAdministrator()
            || ($currentMediaFile !== null && $sourceUpload?->user_id === $actor->id)
            || ($currentMediaFile === null && $uploadCount > 0 && $otherUserUploads === 0);
        $state = match (true) {
            $deleting => 'deleting',
            $failedUploads > 0 => 'failed',
            $activeUploads > 0 => 'in_progress',
            $currentMediaFile !== null => 'available',
            default => 'orphaned',
        };
        $canDelete = $authorized && ($deleting || ($activeUploads === 0 && $failedUploads === 0));

        return [
            'id' => $mediaItem->id,
            'title' => $mediaItem->title,
            'original_title' => $mediaItem->original_title,
            'release_year' => $mediaItem->release_year,
            'tmdb_id' => $mediaItem->tmdb_id,
            'imdb_id' => $mediaItem->imdb_id,
            'poster_url' => $mediaItem->poster_path === null
                ? null
                : 'https://image.tmdb.org/t/p/w342'.$mediaItem->poster_path,
            'state' => $state,
            'current_file' => $currentMediaFile === null ? null : [
                'id' => $currentMediaFile->id,
                'disk' => [
                    'id' => $currentMediaFile->disk_id,
                    'label' => $this->diskRegistry->find($currentMediaFile->disk_id)?->label,
                ],
                'relative_path' => $currentMediaFile->relative_path,
                'size_bytes' => $currentMediaFile->size_bytes,
                'technical_tags' => $this->technicalTagPresenter->present($currentMediaFile),
                'finalized_at' => $currentMediaFile->finalized_at->toISOString(),
                'owner' => $owner === null ? null : [
                    'id' => $owner->id,
                    'name' => $owner->name,
                ],
            ],
            'can_delete' => $canDelete,
            'deletion_blocker' => $this->deletionBlocker(
                $authorized,
                $deleting,
                $activeUploads,
                $failedUploads,
            ),
            'can_reidentify' => $actor->isAdministrator(),
            'reidentification_blocker' => $this->reidentificationBlocker(
                $actor,
                $deleting,
                $activeUploads,
                $failedUploads,
            ),
            'reidentification' => $latestReidentification === null ? null : [
                'id' => $latestReidentification->id,
                'status' => $latestReidentification->status,
                'error_code' => $latestReidentification->error_code,
                'error_detail' => $latestReidentification->error_detail,
                'completed_at' => $latestReidentification->completed_at?->toISOString(),
            ],
        ];
    }

    private function deletionBlocker(
        bool $authorized,
        bool $deleting,
        int $activeUploads,
        int $failedUploads,
    ): ?string {
        if (! $authorized) {
            return 'Only this movie\'s owner or an administrator may delete it.';
        }

        if ($deleting) {
            return null;
        }

        if ($failedUploads > 0) {
            return 'Discard or successfully retry every failed upload before deleting this movie.';
        }

        if ($activeUploads > 0) {
            return 'Finish or cancel every active upload before deleting this movie.';
        }

        return null;
    }

    private function reidentificationBlocker(
        User $actor,
        bool $deleting,
        int $activeUploads,
        int $failedUploads,
    ): ?string {
        if (! $actor->isAdministrator()) {
            return 'Only an administrator may change movie identification.';
        }

        if ($deleting) {
            return 'A movie being deleted cannot be re-identified.';
        }

        if ($failedUploads > 0) {
            return 'Discard or successfully retry every failed upload before changing identification.';
        }

        if ($activeUploads > 0) {
            return 'Finish or cancel every active upload before changing identification.';
        }

        return null;
    }
}
