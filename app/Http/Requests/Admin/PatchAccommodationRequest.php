<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contrato del PATCH de la ficha del establecimiento — el que usa el PMS.
 *
 * El controlador persiste `$request->validated()`, así que **lo que no esté acá
 * se descarta sin 422**. Al agregar un campo al formulario del PMS hay que
 * sumarlo a estas reglas y al `$fillable` de `Accommodation`, o el guardado
 * queda mudo. Ver `docs/openapi-admin.yaml`.
 */
class PatchAccommodationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'               => ['sometimes', 'string', 'min:3', 'max:255'],
            'legal_name'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'account_id'         => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'plan_id'            => ['sometimes', 'nullable', 'integer', 'exists:plans,id'],
            'type_id'            => ['sometimes', 'nullable', 'integer'],
            'stars'              => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'email'              => ['sometimes', 'nullable', 'email'],
            'phone'              => ['sometimes', 'nullable', 'string', 'max:50'],
            'email_reservations' => ['sometimes', 'nullable', 'email'],
            'phone_reservations' => ['sometimes', 'nullable', 'string', 'max:50'],
            'web'                => ['sometimes', 'nullable', 'url'],
            'address'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'address2'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'landmark'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code'        => ['sometimes', 'nullable', 'string', 'max:20'],
            'city_id'            => ['sometimes', 'nullable', 'integer'],
            'state_id'           => ['sometimes', 'nullable', 'integer'],
            'country_id'         => ['sometimes', 'nullable', 'string', 'max:10'],
            'currency_id'        => ['sometimes', 'nullable', 'string', 'max:4'],
            'language_id'        => ['sometimes', 'nullable', 'string', 'max:4'],
            // Identificador IANA ("America/Argentina/Salta"); la regla `timezone`
            // valida contra el catálogo de PHP, así que no entra basura.
            'timezone'           => ['sometimes', 'nullable', 'timezone'],
            'latitude'           => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude'          => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'file_number'        => ['sometimes', 'nullable', 'string', 'max:50'],
            'tax_identification' => ['sometimes', 'nullable', 'string', 'max:50'],
            'channel_code'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'bank_data'          => ['sometimes', 'nullable', 'string'],
            'comment'            => ['sometimes', 'nullable', 'string'],
            'enabled'            => ['sometimes', 'nullable', 'boolean'],
            'active'             => ['sometimes', 'nullable', 'boolean'],
            'test'               => ['sometimes', 'nullable', 'boolean'],
            'expiration'         => ['sometimes', 'nullable', 'date'],
        ];
    }
}
