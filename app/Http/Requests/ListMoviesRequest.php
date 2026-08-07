<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMoviesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['available', 'in_progress', 'failed', 'orphaned', 'deleting'])],
            'sort' => ['nullable', 'string', Rule::in(['newest', 'title'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $search = $this->string('search')->trim()->value();

        $this->merge([
            'search' => $search === '' ? null : $search,
            'status' => $this->input('status') ?: null,
            'sort' => $this->input('sort') ?: 'newest',
        ]);
    }
}
