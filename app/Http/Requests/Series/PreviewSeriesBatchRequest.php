<?php

namespace App\Http\Requests\Series;

use Illuminate\Foundation\Http\FormRequest;

class PreviewSeriesBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.source_identity' => ['required', 'string', 'max:1024'],
            'items.*.series_episode_id' => ['required', 'integer', 'min:1'],
            'items.*.declared_size' => ['required', 'integer', 'min:1'],
            'items.*.replaces_media_file_id' => ['nullable', 'integer', 'min:1'],
            'items.*.replacement_confirmed' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{items:list<array<string, mixed>>} */
    public function payload(): array
    {
        $items = $this->validated('items');
        $normalizedItems = [];

        if (! is_array($items) || ! array_is_list($items)) {
            abort(422);
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                abort(422);
            }

            $normalizedItem = [];

            foreach ($item as $key => $value) {
                if (! is_string($key)) {
                    abort(422);
                }

                $normalizedItem[$key] = $value;
            }

            $normalizedItems[] = $normalizedItem;
        }

        return ['items' => $normalizedItems];
    }
}
