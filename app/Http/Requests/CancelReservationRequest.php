<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelReservationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
            // Sólo para solicitudes del motor: avisarle al huésped el rechazo.
            'notify_guest' => ['sometimes', 'boolean'],
        ];
    }
}
