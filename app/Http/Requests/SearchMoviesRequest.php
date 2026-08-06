<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchMoviesRequest extends FormRequest
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
            'query' => ['required', 'string', 'max:200'],
            'year' => ['nullable', 'integer', 'min:1888', 'max:'.(now()->year + 2)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['query' => $this->string('query')->squish()->toString()]);
    }
}
