<?php

namespace App\Http\Requests\Series;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SuggestSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'source_name' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sourceName = $this->input('source_name');

                if (is_string($sourceName) && preg_match('#[/\\\\\x00-\x1F\x7F]#u', $sourceName) === 1) {
                    $validator->errors()->add('source_name', 'The source name must not contain a path.');
                }
            },
        ];
    }
}
