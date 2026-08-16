<?php

namespace App\Http\Requests\Series;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tmdb_id' => ['required', 'integer', 'min:1'],
            'category' => ['required', Rule::in(['tv', 'anime'])],
            'season_numbers' => ['sometimes', 'array', 'max:100'],
            'season_numbers.*' => ['integer', 'min:0', 'max:9999', 'distinct'],
        ];
    }
}
