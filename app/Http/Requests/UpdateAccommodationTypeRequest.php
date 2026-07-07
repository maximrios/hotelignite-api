<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccommodationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('type')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('accommodation_types', 'name')->ignore($id)],
            'slug' => ['sometimes', 'string', 'max:100', Rule::unique('accommodation_types', 'slug')->ignore($id)],
        ];
    }
}
