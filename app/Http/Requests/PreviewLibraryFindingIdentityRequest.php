<?php

namespace App\Http\Requests;

use App\Enums\MediaRootKind;
use App\Enums\SeriesCategory;
use App\Models\LibraryFinding;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $isSeries = $this->route('libraryFinding') instanceof LibraryFinding
            && $this->route('libraryFinding')->root_kind === MediaRootKind::Series;

        return [
            'tmdb_id' => ['required', 'integer', 'min:1'],
            'category' => ['nullable', Rule::enum(SeriesCategory::class)],
            'season_number' => [Rule::requiredIf($isSeries), 'nullable', 'integer', 'min:0', 'max:999'],
            'episode_number' => [Rule::requiredIf($isSeries), 'nullable', 'integer', 'min:1', 'max:9999'],
        ];
    }
}
