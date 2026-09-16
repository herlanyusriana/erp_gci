<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyPermission(['part.create', 'master.part.create']) ?? false;
    }

    public function rules(): array
    {
        $partId = $this->route('part')?->id;

        return [
            'part_number' => ['required', 'string', 'max:120', 'unique:parts,part_number,' . ($partId ?? 'NULL')],
            'part_name' => ['required', 'string', 'max:255'],
            'part_type_id' => ['required', 'exists:part_types,id'],
            'model' => ['nullable', 'string', 'max:255'],
            'uom_id' => ['nullable', 'exists:uoms,id'],
            'size' => ['nullable', 'string', 'max:255'],
            'nett_weight' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}