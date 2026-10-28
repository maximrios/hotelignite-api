<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NoShowReservationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
