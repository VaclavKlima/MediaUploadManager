<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMovieUploadRequest extends FormRequest
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
            'idempotency_key' => ['required', 'uuid'],
            'filename' => ['required', 'string', 'max:255', 'not_regex:#[/\\\\\x00-\x1F\x7F]#u'],
            'declared_size' => ['required', 'integer', 'min:1'],
            'last_modified_milliseconds' => ['nullable', 'integer', 'min:0'],
            'fingerprint_first_sha256' => ['required', 'string', 'size:64', 'lowercase', 'regex:/\A[a-f0-9]{64}\z/'],
            'fingerprint_last_sha256' => ['required', 'string', 'size:64', 'lowercase', 'regex:/\A[a-f0-9]{64}\z/'],
            'disk_id' => ['required', 'string', 'max:64', 'regex:/\A[a-z][a-z0-9_]*\z/'],
            'replaces_media_file_id' => ['nullable', 'integer', 'min:1'],
            'replacement_confirmed' => $this->filled('replaces_media_file_id')
                ? ['required', 'accepted']
                : ['nullable'],
        ];
    }
}
