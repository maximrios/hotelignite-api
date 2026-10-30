<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** "Olvidé mi contraseña": sólo el email. */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ruta pública
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
