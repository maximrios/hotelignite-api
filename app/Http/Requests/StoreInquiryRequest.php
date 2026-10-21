<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta legacy por `v1` (fechas d/m/Y). Una consulta es sobre un alojamiento o
 * sobre una experiencia, no las dos; la de alojamiento exige check-in.
 */
class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accommodation_id' => ['sometimes', 'nullable', 'integer', 'prohibits:experience_id'],
            'experience_id' => ['sometimes', 'nullable', 'integer'],
            'name' => ['required', 'string', 'min:3'],
            'lastname' => ['required', 'string', 'min:2'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string'],
            'adults' => ['required', 'integer', 'min:1'],
            'childrens' => ['required', 'integer', 'min:0'],
            'checkin' => ['required_without:experience_id', 'nullable', 'string'],
            'checkout' => ['sometimes', 'nullable', 'string'],
            'message' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
