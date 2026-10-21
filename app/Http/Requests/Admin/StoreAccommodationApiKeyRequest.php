<?php

namespace App\Http\Requests\Admin;

use App\Models\AccommodationApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccommodationApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por AccommodationPolicy en el controller
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => [Rule::in(AccommodationApiKey::ABILITIES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
