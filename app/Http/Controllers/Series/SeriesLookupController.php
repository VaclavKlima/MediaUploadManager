<?php

namespace App\Http\Controllers\Series;

use App\Actions\Series\CreateOrUpdateSeries;
use App\Enums\SeriesCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Series\ConfirmSeriesRequest;
use App\Http\Requests\Series\SearchSeriesRequest;
use App\Http\Requests\Series\SuggestSeriesRequest;
use App\Models\Series;
use App\Models\User;
use App\Support\Tmdb\Data\ParsedFilename;
use App\Support\Tmdb\FilenameParser;
use App\Support\Tmdb\SeriesSourceParser;
use App\Support\Tmdb\SeriesSuggestionFinder;
use App\Support\Tmdb\SeriesTmdbClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeriesLookupController extends Controller
{
    public function __construct(
        private readonly SeriesTmdbClient $tmdb,
        private readonly FilenameParser $filenameParser,
        private readonly SeriesSourceParser $seriesSourceParser,
        private readonly SeriesSuggestionFinder $seriesSuggestionFinder,
    ) {}

    public function search(SearchSeriesRequest $request): JsonResponse
    {
        $parsed = $this->filenameParser->parse($request->string('query')->toString());
        $source = new ParsedFilename(
            filename: $parsed->filename,
            title: $parsed->title,
            year: $request->filled('year') ? $request->integer('year') : $parsed->year,
            searchVariants: $parsed->searchVariants,
        );
        $results = $this->seriesSuggestionFinder->find($source);

        return response()->json([
            'data' => $this->summaries($results),
            'meta' => ['source' => 'text'],
        ]);
    }

    public function suggestions(SuggestSeriesRequest $request): JsonResponse
    {
        $parsed = $this->seriesSourceParser->parse($request->string('source_name')->toString());
        $results = $this->seriesSuggestionFinder->find($parsed);

        return response()->json([
            'data' => $this->summaries($results),
            'meta' => [
                'source' => 'filename',
                'parsed' => [
                    'title' => $parsed->title,
                    'year' => $parsed->year,
                ],
            ],
        ]);
    }

    public function show(Request $request, int $tmdbId): JsonResponse
    {
        abort_unless($request->user() !== null, 401);
        $series = $this->tmdb->series($tmdbId);

        return response()->json(['data' => [
            ...$series,
            'poster_url' => $series['poster_path'] === null ? null : 'https://image.tmdb.org/t/p/w500'.$series['poster_path'],
        ]]);
    }

    public function season(Request $request, int $tmdbId, int $seasonNumber): JsonResponse
    {
        abort_unless($request->user() !== null, 401);
        abort_if($seasonNumber < 0, 404);

        return response()->json(['data' => $this->tmdb->season($tmdbId, $seasonNumber)]);
    }

    public function confirm(ConfirmSeriesRequest $request, CreateOrUpdateSeries $action): JsonResponse
    {
        $seasonNumbers = [];

        foreach ($request->array('season_numbers') as $seasonNumber) {
            if (is_int($seasonNumber)) {
                $seasonNumbers[] = $seasonNumber;
            }
        }

        $series = $action->execute(
            $request->integer('tmdb_id'),
            SeriesCategory::from($request->string('category')->toString()),
            $seasonNumbers,
        );

        return response()->json(['data' => $this->present($series, $this->user($request))]);
    }

    public function hydrateSeason(Request $request, Series $series, int $seasonNumber, CreateOrUpdateSeries $action): JsonResponse
    {
        $user = $this->user($request);
        abort_if($seasonNumber < 0, 404);
        $series = $action->execute($series->tmdb_id, $series->category, [$seasonNumber]);

        return response()->json(['data' => $this->present($series, $user)]);
    }

    /** @return array<string, mixed> */
    private function present(Series $series, User $user): array
    {
        $seasons = [];

        foreach ($series->seasons as $season) {
            $episodes = [];

            foreach ($season->episodes as $episode) {
                $episodes[] = [
                    'id' => $episode->getKey(),
                    'episode_number' => $episode->episode_number,
                    'identity' => sprintf('S%02dE%02d', $season->season_number, $episode->episode_number),
                    'name' => $episode->name,
                    'has_current_primary' => $episode->current_media_file_id !== null,
                    'can_replace_current_primary' => $episode->currentMediaFile === null
                        || $episode->currentMediaFile->sourceUpload?->user_id === $user->getKey()
                        || $user->isAdministrator(),
                    'current_primary' => $episode->currentMediaFile === null ? null : [
                        'id' => $episode->currentMediaFile->getKey(),
                        'relative_path' => $episode->currentMediaFile->relative_path,
                        'size_bytes' => $episode->currentMediaFile->size_bytes,
                    ],
                ];
            }

            $seasons[] = [
                'id' => $season->getKey(),
                'season_number' => $season->season_number,
                'name' => $season->displayName(),
                'episodes' => $episodes,
            ];
        }

        $hydratedSeasonNumbers = [];

        foreach ($series->seasons as $season) {
            $hydratedSeasonNumbers[$season->season_number] = true;
        }

        $availableSeasons = [];

        foreach ($this->availableSeasonMetadata($series) as $season) {
            $seasonNumber = $season['season_number'];
            $name = $seasonNumber === 0 ? 'Specials' : $season['name'];
            $availableSeasons[] = [
                'season_number' => $seasonNumber,
                'name' => $name !== '' ? $name : 'Season '.$seasonNumber,
                'episode_count' => $season['episode_count'],
                'hydrated' => isset($hydratedSeasonNumbers[$seasonNumber]),
            ];
        }

        return [
            'id' => $series->getKey(),
            'tmdb_id' => $series->tmdb_id,
            'name' => $series->name,
            'original_name' => $series->original_name,
            'first_air_year' => $series->first_air_year,
            'overview' => $series->overview,
            'category' => $series->category->value,
            'poster_url' => $series->poster_path === null ? null : 'https://image.tmdb.org/t/p/w500'.$series->poster_path,
            'home_disk_id' => $series->home_disk_id,
            'episode_total' => $series->episode_total,
            'available_seasons' => $availableSeasons,
            'seasons' => $seasons,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return list<array{season_number:int,name:string,episode_count:int}> */
    private function availableSeasonMetadata(Series $series): array
    {
        $metadata = $series->metadata_snapshot['seasons'] ?? null;

        if (! is_array($metadata)) {
            return [];
        }

        $seasons = [];

        foreach ($metadata as $season) {
            if (! is_array($season)
                || ! is_int($season['season_number'] ?? null)
                || ! is_int($season['episode_count'] ?? null)
            ) {
                continue;
            }

            $name = $season['name'] ?? '';
            $seasons[] = [
                'season_number' => $season['season_number'],
                'name' => is_string($name) ? $name : '',
                'episode_count' => $season['episode_count'],
            ];
        }

        usort($seasons, fn (array $left, array $right): int => $left['season_number'] <=> $right['season_number']);

        return $seasons;
    }

    /**
     * @param  list<array{tmdb_id:int,name:string,original_name:string|null,first_air_date:string|null,first_air_year:int|null,overview:string|null,poster_path:string|null,original_language:string|null}>  $results
     * @return list<array<string, mixed>>
     */
    private function summaries(array $results): array
    {
        return array_map(fn (array $series): array => [
            ...$series,
            'poster_url' => $series['poster_path'] === null ? null : 'https://image.tmdb.org/t/p/w500'.$series['poster_path'],
        ], $results);
    }
}
