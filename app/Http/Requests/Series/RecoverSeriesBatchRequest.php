<?php

namespace App\Http\Requests\Series;

use Illuminate\Foundation\Http\FormRequest;

class RecoverSeriesBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:1000'],
            'items.*.upload_uuid' => ['required', 'uuid', 'distinct:strict'],
            'items.*.source_identity' => ['required', 'string', 'max:1024', 'distinct:strict'],
            'items.*.filename' => ['required', 'string', 'max:255'],
            'items.*.declared_size' => ['required', 'integer', 'min:1'],
            'items.*.last_modified_milliseconds' => ['nullable', 'integer', 'min:0'],
            'items.*.fingerprint_first_sha256' => ['required', 'string', 'size:64', 'lowercase', 'regex:/\A[a-f0-9]{64}\z/'],
            'items.*.fingerprint_last_sha256' => ['required', 'string', 'size:64', 'lowercase', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    /** @return list<array{upload_uuid:string,source_identity:string,filename:string,declared_size:int,last_modified_milliseconds:int|null,fingerprint_first_sha256:string,fingerprint_last_sha256:string}> */
    public function items(): array
    {
        /** @var list<array{upload_uuid:string,source_identity:string,filename:string,declared_size:int,last_modified_milliseconds:int|null,fingerprint_first_sha256:string,fingerprint_last_sha256:string}> $items */
        $items = $this->validated('items');

        return $items;
    }
}
