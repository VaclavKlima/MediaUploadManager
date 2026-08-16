<?php

namespace App\Actions\Series;

use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\Models\EpisodeRenameOperation;
use App\Models\MediaFile;
use App\Models\SeriesDeletionOperation;
use App\Models\SeriesEpisode;
use App\Models\User;
use App\Support\Series\JellyfinSeriesPathBuilder;
use Illuminate\Support\Str;

final readonly class PreviewEpisodeRename
{
    public function __construct(private JellyfinSeriesPathBuilder $pathBuilder) {}

    /**
     * @return array{
     *     episode_id:int,
     *     tmdb_name:string,
     *     current_name:string,
     *     custom_name:string|null,
     *     has_current_file:bool,
     *     source_relative_path:string|null,
     *     destination_relative_path:string|null,
     *     path_changes:bool,
     *     can_rename:bool,
     *     blocker:string|null
     * }
     */
    public function execute(SeriesEpisode $episode, User $actor, ?string $customName): array
    {
        $episode->loadMissing('season.series', 'currentMediaFile.sourceUpload');
        $normalizedName = $this->normalize($customName);
        $mediaFile = $episode->currentMediaFile;
        $authorized = $mediaFile === null
            ? $actor->isAdministrator()
            : $actor->isAdministrator()
                || $mediaFile->sourceUpload?->user_id === $actor->id
                || $mediaFile->imported_by_user_id === $actor->id;
        $blocker = $this->blocker($episode, $authorized, $normalizedName);
        $destination = null;

        if ($mediaFile !== null) {
            $originalCustomName = $episode->custom_name;
            $episode->custom_name = $normalizedName;

            try {
                $destination = $this->pathBuilder->build($episode, $mediaFile->relative_path)->relativePath;
            } finally {
                $episode->custom_name = $originalCustomName;
            }

            if ($blocker === null && $destination !== $mediaFile->relative_path
                && MediaFile::query()->where('active_path_key', MediaFile::activePathKey(
                    $mediaFile->disk_id,
                    $destination,
                    MediaRootKind::Series,
                ))->exists()
            ) {
                $blocker = 'The canonical destination is already occupied.';
            }
        }

        return [
            'episode_id' => $episode->id,
            'tmdb_name' => $episode->name,
            'current_name' => $episode->displayName(),
            'custom_name' => $normalizedName,
            'has_current_file' => $mediaFile !== null,
            'source_relative_path' => $mediaFile?->relative_path,
            'destination_relative_path' => $destination,
            'path_changes' => $mediaFile !== null && $destination !== $mediaFile->relative_path,
            'can_rename' => $blocker === null,
            'blocker' => $blocker,
        ];
    }

    public function normalize(?string $customName): ?string
    {
        if ($customName === null) {
            return null;
        }

        $normalized = Str::squish($customName);

        return $normalized === '' ? null : $normalized;
    }

    private function blocker(SeriesEpisode $episode, bool $authorized, ?string $customName): ?string
    {
        if (! $authorized) {
            return $episode->current_media_file_id === null
                ? 'Only an administrator may rename a missing episode.'
                : 'Only this episode\'s owner or an administrator may rename it.';
        }

        if ($customName === $episode->custom_name) {
            return 'The episode title is unchanged.';
        }

        $seriesId = $episode->season->series_id;

        if (SeriesDeletionOperation::query()->where('series_id', $seriesId)->whereNot('status', 'completed')->exists()) {
            return 'A deletion operation is unresolved.';
        }

        $operation = EpisodeRenameOperation::query()->where('series_episode_id', $episode->id)
            ->whereNot('status', 'completed')->first();

        if ($operation !== null && $operation->new_custom_name !== $customName) {
            return 'Retry must use the title pinned by the unresolved rename operation.';
        }

        if ($episode->uploads()->where('status', UploadStatus::Failed)->exists()) {
            return 'Discard or successfully retry the failed upload first.';
        }

        if ($episode->uploads()->whereIn('status', UploadStatus::capacityReservingValues())->exists()) {
            return 'Finish or cancel the active upload first.';
        }

        return null;
    }
}
