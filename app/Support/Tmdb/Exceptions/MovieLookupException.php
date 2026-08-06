<?php

namespace App\Support\Tmdb\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class MovieLookupException extends RuntimeException implements ShouldntReport
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('movie_not_found', 404, 'The requested movie could not be found.');
    }

    public static function rateLimited(): self
    {
        return new self('movie_lookup_rate_limited', 503, 'Movie lookup is temporarily rate limited. Please try again later.');
    }

    public static function unavailable(): self
    {
        return new self('movie_lookup_unavailable', 503, 'Movie lookup is temporarily unavailable.');
    }

    public static function invalidResponse(): self
    {
        return new self('movie_lookup_invalid_response', 502, 'The movie service returned an invalid response.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->statusCode);
    }
}
