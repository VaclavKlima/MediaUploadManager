<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportLibraryFindingsRequest extends FormRequest
{
    /** @return list<int> */
    public function findingIds(): array
    {
        $findingIds = $this->validated('finding_ids');

        if (! is_array($findingIds)) {
            return [];
        }

        return array_values(array_map(function (mixed $findingId): int {
            if (is_int($findingId)) {
                return $findingId;
            }

            if (! is_string($findingId) || ! ctype_digit($findingId)) {
                throw new \LogicException('A validated library finding ID is invalid.');
            }

            return (int) $findingId;
        }, $findingIds));
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isAdministrator();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'finding_ids' => ['required', 'array', 'min:1', 'max:100'],
            'finding_ids.*' => ['required', 'integer', 'distinct', 'exists:library_findings,id'],
        ];
    }
}
