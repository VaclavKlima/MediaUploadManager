<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoviePathPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'declared_size' => ['required', 'integer', 'min:1'],
        ];
    }
}
