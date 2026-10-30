<?php

namespace App\Http\Requests\UserInvitations;

use Illuminate\Foundation\Http\FormRequest;

/** La contraseña que elige el user desde el link del email de bienvenida. */
class AcceptUserInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ruta pública; el token hace el control
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
