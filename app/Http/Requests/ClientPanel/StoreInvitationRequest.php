<?php

namespace App\Http\Requests\ClientPanel;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Emisión de invitaciones desde el panel de client. Acepta un lote de filas: el
 * email es obligatorio; el nombre de la persona a cargo y el del establecimiento
 * son opcionales, porque el client conoce a sus prestadores. Personalizan el
 * mail y vuelven legible el padrón. La tenencia (`client_id`) sale del token en
 * el controller, nunca del body.
 */
class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `client.user`
    }

    public function rules(): array
    {
        return [
            'invitations' => ['required', 'array', 'min:1', 'max:500'],
            'invitations.*.email' => ['required', 'email', 'max:255'],
            'invitations.*.contact_name' => ['nullable', 'string', 'max:255'],
            'invitations.*.accommodation_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
