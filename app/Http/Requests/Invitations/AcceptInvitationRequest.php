<?php

namespace App\Http\Requests\Invitations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload del accept público. Los campos son nullable acá porque cuáles son
 * obligatorios depende del caso (A/B/C) y de si ya existe un usuario con ese
 * email — eso lo decide el repositorio, que es donde se resuelve el caso.
 */
class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ruta pública; el token y las guardas de §6 hacen el control
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'accommodation_name' => ['nullable', 'string', 'min:3', 'max:255'],
        ];
    }
}
