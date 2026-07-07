<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyAccommodationServiceRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_service_id' => ['required', 'integer', 'exists:accommodation_services,id'],
        ];
    }
}




