<?php

namespace App\Http\Requests;

use App\Support\Media\UploadConfiguration;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TusHookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(UploadConfiguration $configuration): bool
    {
        return $configuration->hookSecretMatches($this->header('X-Tus-Hook-Secret'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'Type' => ['required', 'string', Rule::in([
                'pre-create',
                'post-create',
                'post-receive',
                'post-finish',
                'pre-terminate',
                'post-terminate',
            ])],
            'Event' => ['required', 'array'],
            'Event.Upload' => ['required', 'array'],
            'Event.Upload.ID' => ['nullable', 'string', 'max:255'],
            'Event.Upload.Size' => ['required', 'integer', 'min:0'],
            'Event.Upload.SizeIsDeferred' => ['required', 'boolean'],
            'Event.Upload.Offset' => ['required', 'integer', 'min:0'],
            'Event.Upload.MetaData' => ['required', 'array'],
            'Event.Upload.MetaData.*' => ['string', 'max:500'],
            'Event.Upload.IsPartial' => ['required', 'boolean'],
            'Event.Upload.IsFinal' => ['required', 'boolean'],
            'Event.Upload.PartialUploads' => ['nullable', 'array', 'max:0'],
            'Event.Upload.Storage' => ['nullable', 'array'],
            'Event.Upload.Storage.*' => ['string', 'max:4096'],
            'Event.HTTPRequest' => ['sometimes', 'array'],
            'Event.HTTPRequest.Header' => ['sometimes', 'array', 'max:64'],
        ];
    }
}
