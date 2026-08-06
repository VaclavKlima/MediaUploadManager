<?php

namespace App\Support\Tmdb;

use App\Support\Tmdb\Data\MovieDetails;
use App\Support\Tmdb\Data\MovieSummary;
use App\Support\Tmdb\Exceptions\MovieLookupException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class TmdbClient
{
    /** @return list<MovieSummary> */
    public function searchMovies(string $query, ?int $year = null): array
    {
        $parameters = [
            'query' => Str::squish($query),
            'include_adult' => false,
        ];

        if ($year !== null) {
            $parameters['primary_release_year'] = $year;
        }

        $movies = $this->cachedRequest('search/movie', $parameters, fn (array $payload): array => $this->normalizeSearch($payload));

        return array_map(fn (array $movie): MovieSummary => MovieSummary::fromArray($movie), $movies);
    }

    public function movie(int $tmdbId): MovieDetails
    {
        $movie = $this->cachedRequest('movie/'.$tmdbId, [], fn (array $payload): array => $this->normalizeDetails($payload));

        return MovieDetails::fromArray($movie);
    }

    public function findByImdb(string $imdbId): MovieDetails
    {
        $matches = $this->cachedRequest(
            'find/'.Str::lower($imdbId),
            ['external_source' => 'imdb_id'],
            fn (array $payload): array => $this->normalizeFind($payload),
        );

        if ($matches === []) {
            throw MovieLookupException::notFound();
        }

        return $this->movie($matches[0]);
    }

    /**
     * @template T of array<mixed>
     *
     * @param  array<string, bool|int|string>  $parameters
     * @param  callable(array<string, mixed>): T  $normalize
     * @return T
     */
    private function cachedRequest(string $endpoint, array $parameters, callable $normalize): array
    {
        $language = $this->configuredString('language', 'en-US');
        $parameters['language'] = $language;
        ksort($parameters);
        $cacheKey = 'tmdb:'.$endpoint.':'.$language.':'.hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));

        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                /** @var T $cached */
                return $cached;
            }
        }

        $normalized = $normalize($this->request($endpoint, $parameters));
        Cache::put($cacheKey, $normalized, max(1, $this->configuredInteger('cache_ttl', 86400)));

        return $normalized;
    }

    /**
     * @param  array<string, bool|int|string>  $parameters
     * @return array<string, mixed>
     */
    private function request(string $endpoint, array $parameters): array
    {
        $token = $this->configuredString('token');
        $baseUrl = $this->configuredString('base_url');

        if ($token === '' || $baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw MovieLookupException::unavailable();
        }

        try {
            $response = Http::baseUrl(rtrim($baseUrl, '/'))
                ->acceptJson()
                ->withToken($token)
                ->connectTimeout(max(1, $this->configuredInteger('connect_timeout', 3)))
                ->timeout(max(1, $this->configuredInteger('request_timeout', 10)))
                ->retry(
                    [100, 300],
                    when: fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response->status() === 429 || $exception->response->serverError())),
                    throw: false,
                )
                ->get($endpoint, $parameters);
        } catch (ConnectionException) {
            throw MovieLookupException::unavailable();
        }

        $this->ensureSuccessful($response);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw MovieLookupException::invalidResponse();
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->notFound()) {
            throw MovieLookupException::notFound();
        }

        if ($response->status() === 429) {
            throw MovieLookupException::rateLimited();
        }

        throw MovieLookupException::unavailable();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{tmdb_id: int, title: string, original_title: string|null, release_date: string|null, release_year: int|null, overview: string|null, poster_path: string|null, original_language: string|null}>
     */
    private function normalizeSearch(array $payload): array
    {
        $results = $payload['results'] ?? null;

        if (! is_array($results) || ! array_is_list($results)) {
            throw MovieLookupException::invalidResponse();
        }

        return array_map(function (mixed $movie): array {
            $movie = $this->objectPayload($movie);

            $tmdbId = $movie['id'] ?? null;
            $title = $movie['title'] ?? null;

            if (! is_int($tmdbId) || $tmdbId < 1 || ! is_string($title) || $title === '') {
                throw MovieLookupException::invalidResponse();
            }

            $releaseDate = $this->optionalDate($movie, 'release_date');

            return [
                'tmdb_id' => $tmdbId,
                'title' => $title,
                'original_title' => $this->optionalString($movie, 'original_title'),
                'release_date' => $releaseDate,
                'release_year' => $releaseDate === null ? null : (int) substr($releaseDate, 0, 4),
                'overview' => $this->optionalString($movie, 'overview'),
                'poster_path' => $this->optionalString($movie, 'poster_path'),
                'original_language' => $this->optionalString($movie, 'original_language'),
            ];
        }, $results);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function normalizeFind(array $payload): array
    {
        $results = $payload['movie_results'] ?? null;

        if (! is_array($results) || ! array_is_list($results)) {
            throw MovieLookupException::invalidResponse();
        }

        return array_map(function (mixed $movie): int {
            if (! is_array($movie) || ! is_int($movie['id'] ?? null) || $movie['id'] < 1) {
                throw MovieLookupException::invalidResponse();
            }

            return $movie['id'];
        }, $results);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{tmdb_id: int, imdb_id: string|null, title: string, original_title: string|null, release_date: string|null, release_year: int|null, overview: string|null, poster_path: string|null, original_language: string|null, runtime: int|null, status: string|null, tagline: string|null, vote_average: float|null, vote_count: int|null, genres: list<array{id: int, name: string}>}
     */
    private function normalizeDetails(array $payload): array
    {
        $tmdbId = $payload['id'] ?? null;
        $title = $payload['title'] ?? null;

        if (! is_int($tmdbId) || $tmdbId < 1 || ! is_string($title) || $title === '') {
            throw MovieLookupException::invalidResponse();
        }

        $releaseDate = $this->optionalDate($payload, 'release_date');
        $genres = $payload['genres'] ?? [];

        if (! is_array($genres) || ! array_is_list($genres)) {
            throw MovieLookupException::invalidResponse();
        }

        $normalizedGenres = array_map(function (mixed $genre): array {
            if (! is_array($genre) || ! is_int($genre['id'] ?? null) || ! is_string($genre['name'] ?? null)) {
                throw MovieLookupException::invalidResponse();
            }

            return ['id' => $genre['id'], 'name' => $genre['name']];
        }, $genres);

        $voteAverage = $payload['vote_average'] ?? null;

        if ($voteAverage !== null && ! is_int($voteAverage) && ! is_float($voteAverage)) {
            throw MovieLookupException::invalidResponse();
        }

        return [
            'tmdb_id' => $tmdbId,
            'imdb_id' => $this->optionalImdbId($payload),
            'title' => $title,
            'original_title' => $this->optionalString($payload, 'original_title'),
            'release_date' => $releaseDate,
            'release_year' => $releaseDate === null ? null : (int) substr($releaseDate, 0, 4),
            'overview' => $this->optionalString($payload, 'overview'),
            'poster_path' => $this->optionalString($payload, 'poster_path'),
            'original_language' => $this->optionalString($payload, 'original_language'),
            'runtime' => $this->optionalInteger($payload, 'runtime'),
            'status' => $this->optionalString($payload, 'status'),
            'tagline' => $this->optionalString($payload, 'tagline'),
            'vote_average' => $voteAverage === null ? null : (float) $voteAverage,
            'vote_count' => $this->optionalInteger($payload, 'vote_count'),
            'genres' => $normalizedGenres,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw MovieLookupException::invalidResponse();
        }

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $payload */
    private function optionalInteger(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        if ($value !== null && ! is_int($value)) {
            throw MovieLookupException::invalidResponse();
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function optionalDate(array $payload, string $key): ?string
    {
        $value = $this->optionalString($payload, $key);

        if ($value !== null && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw MovieLookupException::invalidResponse();
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function optionalImdbId(array $payload): ?string
    {
        $imdbId = $this->optionalString($payload, 'imdb_id');

        if ($imdbId !== null && preg_match('/\Att\d{7,12}\z/', $imdbId) !== 1) {
            throw MovieLookupException::invalidResponse();
        }

        return $imdbId;
    }

    private function configuredString(string $key, string $default = ''): string
    {
        $value = config('services.tmdb.'.$key, $default);

        return is_string($value) ? trim($value) : $default;
    }

    private function configuredInteger(string $key, int $default): int
    {
        $value = config('services.tmdb.'.$key, $default);

        return is_int($value) ? $value : $default;
    }

    /** @return array<string, mixed> */
    private function objectPayload(mixed $payload): array
    {
        if (! is_array($payload) || array_is_list($payload)) {
            throw MovieLookupException::invalidResponse();
        }

        $normalized = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw MovieLookupException::invalidResponse();
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
