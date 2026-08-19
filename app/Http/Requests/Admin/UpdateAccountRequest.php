<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición parcial (PATCH).
 *
 * Todo va con `sometimes` para que el mismo request sirva para un cambio de un
 * campo y para el formulario completo. A diferencia de `Admin\StoreAccountRequest`
 * acepta exactamente los mismos campos: acá no hay la asimetría que sí tienen
 * los FormRequest de `Accommodation` (ver CLAUDE.md del CRM).
 */
class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['sometimes', 'string', 'min:2', 'max:255'],
            'plan_id'         => ['sometimes', 'nullable', 'integer', 'exists:plans,id'],
            'account_type_id' => ['sometimes', 'nullable', 'integer', 'exists:account_types,id'],
            'first_name'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'email'           => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'active'          => ['sometimes', 'nullable', 'boolean'],
            'test'            => ['sometimes', 'nullable', 'boolean'],
            'agreement'       => ['sometimes', 'nullable', 'boolean'],
            'expiration_date' => ['sometimes', 'nullable', 'date'],
            'comments'        => ['sometimes', 'nullable', 'string'],
        ];
    }
}
