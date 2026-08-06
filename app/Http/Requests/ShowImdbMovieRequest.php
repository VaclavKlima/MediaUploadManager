<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShowImdbMovieRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'imdb_id' => ['required', 'string', 'regex:/\Att[0-9]{7,12}\z/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $imdbId = $this->route('imdbId');

        $this->merge(['imdb_id' => is_string($imdbId) ? strtolower($imdbId) : $imdbId]);
    }
}
