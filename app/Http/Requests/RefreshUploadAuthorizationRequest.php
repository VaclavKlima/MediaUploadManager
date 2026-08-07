<?php

namespace App\Http\Requests;

use App\Models\Upload;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RefreshUploadAuthorizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $upload = $this->route('upload');

        return $user instanceof User
            && $upload instanceof Upload
            && ($upload->user_id === $user->getKey() || $user->isAdministrator());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255', 'not_regex:#[/\\\\\x00-\x1F\x7F]#u'],
            'declared_size' => ['required', 'integer', 'min:1'],
            'last_modified_milliseconds' => ['nullable', 'integer', 'min:0'],
            'fingerprint_first_sha256' => ['required', 'string', 'size:64', 'lowercase', 'regex:/\A[a-f0-9]{64}\z/'],
            'fingerprint_last_sha256' => ['required', 'string', 'size:64', 'lowercase', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }
}
