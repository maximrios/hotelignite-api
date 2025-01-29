<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetAccommodationRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer', 'exists:accommodations,id'],
        ];
    }
}
