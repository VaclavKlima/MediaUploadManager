<?php

namespace App\Http\Requests\Series;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeleteSeriesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'deletion_confirmed' => ['accepted'],
            'confirmation_name' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'deletion_confirmed.accepted' => 'Confirm that this Show and all of its tracked media will be permanently deleted.',
            'confirmation_name.required' => 'Type the Show name to confirm deletion.',
        ];
    }
}
