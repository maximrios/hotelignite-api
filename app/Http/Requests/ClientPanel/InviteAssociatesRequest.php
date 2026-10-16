<?php

namespace App\Http\Requests\ClientPanel;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Invitación a alojamientos que ya están en el padrón del client. El `email`
 * sirve sólo para un borrador sin email cargado, así que va con un único
 * alojamiento: en lote, cada uno usa el suyo.
 */
class InviteAssociatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `client.user`
    }

    public function rules(): array
    {
        return [
            'accommodation_ids' => ['required', 'array', 'min:1', 'max:200'],
            'accommodation_ids.*' => ['integer', 'min:1'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->filled('email') && count((array) $this->input('accommodation_ids')) !== 1) {
                $validator->errors()->add('email', 'El email se indica de a un alojamiento por vez.');
            }
        });
    }
}
