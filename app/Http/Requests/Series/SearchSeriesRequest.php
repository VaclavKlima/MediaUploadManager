<?php

namespace App\Http\Requests\Series;

use Illuminate\Foundation\Http\FormRequest;

class SearchSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'year' => ['nullable', 'integer', 'between:1900,2100'],
        ];
    }
}
