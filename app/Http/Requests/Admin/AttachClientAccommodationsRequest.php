<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AttachClientAccommodationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `platform`
    }

    public function rules(): array
    {
        return [
            'accommodation_ids' => ['required', 'array', 'min:1'],
            'accommodation_ids.*' => ['integer', 'exists:accommodations,id'],
        ];
    }
}
