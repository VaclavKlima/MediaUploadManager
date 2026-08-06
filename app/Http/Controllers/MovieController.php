<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrReuseMediaItem;
use App\Http\Requests\ConfirmMovieRequest;
use App\Http\Requests\SearchMoviesRequest;
use App\Http\Requests\ShowImdbMovieRequest;
use App\Http\Requests\ShowTmdbMovieRequest;
use App\Http\Requests\SuggestMoviesRequest;
use App\Models\MediaItem;
use App\Support\Tmdb\Data\MovieDetails;
use App\Support\Tmdb\Data\MovieSummary;
use App\Support\Tmdb\FilenameParser;
use App\Support\Tmdb\MovieSuggestionFinder;
use App\Support\Tmdb\TmdbClient;
use Illuminate\Http\JsonResponse;

class MovieController extends Controller
{
    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly FilenameParser $filenameParser,
        private readonly MovieSuggestionFinder $movieSuggestionFinder,
        private readonly CreateOrReuseMediaItem $createOrReuseMediaItem,
    ) {}

    public function search(SearchMoviesRequest $request): JsonResponse
    {
        $movies = $this->tmdb->searchMovies(
            $request->string('query')->toString(),
            $request->filled('year') ? $request->integer('year') : null,
        );

        return response()->json([
            'data' => $this->summaries($movies),
            'meta' => ['source' => 'text'],
        ]);
    }

    public function suggestions(SuggestMoviesRequest $request): JsonResponse
    {
        $parsed = $this->filenameParser->parse($request->string('filename')->toString());
        $ranked = $this->movieSuggestionFinder->find($parsed);

        return response()->json([
            'data' => $this->summaries($ranked),
            'meta' => [
                'source' => 'filename',
                'parsed' => $parsed->toArray(),
            ],
        ]);
    }

    public function showTmdb(ShowTmdbMovieRequest $request, int $tmdbId): JsonResponse
    {
        return response()->json(['data' => $this->tmdb->movie($tmdbId)->toArray()]);
    }

    public function showImdb(ShowImdbMovieRequest $request, string $imdbId): JsonResponse
    {
        return response()->json(['data' => $this->tmdb->findByImdb(strtolower($imdbId))->toArray()]);
    }

    public function confirm(ConfirmMovieRequest $request): JsonResponse
    {
        $tmdbId = $request->integer('tmdb_id');
        $existingMediaItem = MediaItem::query()->where('tmdb_id', $tmdbId)->first();
        $details = $existingMediaItem === null
            ? $this->tmdb->movie($tmdbId)
            : MovieDetails::fromMediaItem($existingMediaItem);
        $mediaItem = $this->createOrReuseMediaItem->handle($details->mediaItemSnapshot());

        return response()->json([
            'data' => MovieDetails::fromMediaItem($mediaItem)->toArray(),
            'media_item_id' => $mediaItem->getKey(),
            'reused' => ! $mediaItem->wasRecentlyCreated,
            'has_current_primary' => $mediaItem->current_media_file_id !== null,
        ]);
    }

    /**
     * @param  list<MovieSummary>  $movies
     * @return list<array<string, mixed>>
     */
    private function summaries(array $movies): array
    {
        return array_map(fn (MovieSummary $movie): array => $movie->toArray(), $movies);
    }
}
