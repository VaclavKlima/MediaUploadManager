<?php

namespace App\Actions;

use App\Models\MediaItem;
use Illuminate\Database\QueryException;

class CreateOrReuseMediaItem
{
    /**
     * @param  array{
     *     tmdb_id: int,
     *     imdb_id?: string|null,
     *     title: string,
     *     original_title?: string|null,
     *     release_date?: string|null,
     *     release_year?: int|null,
     *     overview?: string|null,
     *     poster_path?: string|null,
     *     original_language?: string|null,
     *     metadata_version: int,
     *     metadata_snapshot: array<string, mixed>
     * }  $snapshot
     */
    public function handle(array $snapshot): MediaItem
    {
        $existingMediaItem = MediaItem::query()
            ->where('tmdb_id', $snapshot['tmdb_id'])
            ->first();

        if ($existingMediaItem !== null) {
            return $existingMediaItem;
        }

        try {
            return MediaItem::query()->create($snapshot);
        } catch (QueryException $exception) {
            $concurrentlyCreatedMediaItem = MediaItem::query()
                ->where('tmdb_id', $snapshot['tmdb_id'])
                ->first();

            if ($concurrentlyCreatedMediaItem !== null) {
                return $concurrentlyCreatedMediaItem;
            }

            throw $exception;
        }
    }
}
