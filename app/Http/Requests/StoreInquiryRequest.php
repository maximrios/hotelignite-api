<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accommodation_id' => ['sometimes', 'nullable'],
            'name'             => ['required', 'string', 'min:3'],
            'lastname'         => ['required', 'string', 'min:2'],
            'email'            => ['required', 'email'],
            'phone'            => ['required', 'string'],
            'adults'           => ['required', 'integer', 'min:1'],
            'childrens'        => ['required', 'integer', 'min:0'],
            'checkin'          => ['required', 'string'],
            'checkout'         => ['sometimes', 'nullable', 'string'],
            'message'          => ['sometimes', 'nullable', 'string'],
        ];
    }
}
