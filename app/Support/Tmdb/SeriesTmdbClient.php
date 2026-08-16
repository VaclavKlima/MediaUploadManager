<?php

namespace App\Support\Tmdb;

final readonly class SeriesTmdbClient
{
    public function __construct(private TmdbClient $gateway) {}

    /** @return list<array{tmdb_id:int,name:string,original_name:string|null,first_air_date:string|null,first_air_year:int|null,overview:string|null,poster_path:string|null,original_language:string|null}> */
    public function search(string $query, ?int $year = null): array
    {
        return $this->gateway->searchTv($query, $year);
    }

    /** @return array{tmdb_id:int,name:string,original_name:string|null,first_air_date:string|null,first_air_year:int|null,overview:string|null,poster_path:string|null,original_language:string|null,number_of_episodes:int,seasons:list<array{tmdb_id:int,season_number:int,name:string,air_date:string|null,episode_count:int,overview:string|null,poster_path:string|null}>,external_ids:array{imdb_id:string|null,tvdb_id:string|null}} */
    public function series(int $tmdbId): array
    {
        return $this->gateway->tv($tmdbId);
    }

    /** @return array{tmdb_id:int,season_number:int,name:string,overview:string|null,poster_path:string|null,air_date:string|null,episodes:list<array{tmdb_id:int,season_number:int,episode_number:int,name:string,overview:string|null,air_date:string|null,runtime_minutes:int|null}>} */
    public function season(int $tmdbId, int $seasonNumber): array
    {
        return $this->gateway->tvSeason($tmdbId, $seasonNumber);
    }

    /** @return array{tmdb_id:int,season_number:int,episode_number:int,name:string,overview:string|null,air_date:string|null,runtime_minutes:int|null} */
    public function episode(int $tmdbId, int $seasonNumber, int $episodeNumber): array
    {
        return $this->gateway->tvEpisode($tmdbId, $seasonNumber, $episodeNumber);
    }
}
