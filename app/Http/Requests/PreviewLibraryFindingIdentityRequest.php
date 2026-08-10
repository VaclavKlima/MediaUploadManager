<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class PreviewLibraryFindingIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isAdministrator();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['tmdb_id' => ['required', 'integer', 'min:1']];
    }
}
