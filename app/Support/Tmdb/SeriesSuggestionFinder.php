<?php

namespace App\Support\Tmdb;

use App\Support\Tmdb\Data\ParsedFilename;

class SeriesSuggestionFinder
{
    public function __construct(private readonly SeriesTmdbClient $tmdb) {}

    /** @return list<array{tmdb_id:int,name:string,original_name:string|null,first_air_date:string|null,first_air_year:int|null,overview:string|null,poster_path:string|null,original_language:string|null}> */
    public function find(ParsedFilename $source): array
    {
        foreach ($this->queries($source) as $query) {
            $results = $this->deduplicate($this->tmdb->search($query['title'], $query['year']));

            if ($results !== []) {
                return $results;
            }
        }

        return [];
    }

    /** @return list<array{title:string,year:int|null}> */
    private function queries(ParsedFilename $source): array
    {
        $queries = [['title' => $source->title, 'year' => $source->year]];

        if ($source->year !== null) {
            $queries[] = ['title' => $source->title, 'year' => null];
        }

        foreach (array_slice($source->searchVariants, 1) as $variant) {
            $queries[] = ['title' => $variant, 'year' => null];
        }

        $unique = [];

        foreach ($queries as $query) {
            $key = mb_strtolower($query['title']).'|'.($query['year'] ?? '');
            $unique[$key] = $query;
        }

        return array_slice(array_values($unique), 0, 3);
    }

    /**
     * @param  list<array{tmdb_id:int,name:string,original_name:string|null,first_air_date:string|null,first_air_year:int|null,overview:string|null,poster_path:string|null,original_language:string|null}>  $results
     * @return list<array{tmdb_id:int,name:string,original_name:string|null,first_air_date:string|null,first_air_year:int|null,overview:string|null,poster_path:string|null,original_language:string|null}>
     */
    private function deduplicate(array $results): array
    {
        $deduplicated = [];

        foreach ($results as $result) {
            $deduplicated[$result['tmdb_id']] ??= $result;
        }

        return array_values($deduplicated);
    }
}
