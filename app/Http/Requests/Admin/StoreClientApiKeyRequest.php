<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientApiKeyRequest extends FormRequest
{
    /**
     * Abilities disponibles para una API key de client. Mantener alineado con
     * los checks de `client.ability` en routes/client-api.php.
     */
    public const ABILITIES = ['catalog:read', 'booking:create'];

    public function authorize(): bool
    {
        return true; // gateado por el middleware `platform`
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => [Rule::in(self::ABILITIES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
