<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccommodationRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name'               => ['sometimes', 'string', 'min:3', 'max:255'],
            'legal_name'         => ['nullable', 'string', 'max:255'],
            'account_id'         => ['nullable', 'integer', 'exists:accounts,id'],
            'plan_id'            => ['nullable', 'integer', 'exists:plans,id'],
            'type_id'            => ['nullable', 'integer'],
            'stars'              => ['nullable', 'integer', 'between:1,5'],
            'email'              => ['nullable', 'email'],
            'phone'              => ['nullable', 'string', 'max:50'],
            'email_reservations' => ['nullable', 'email'],
            'phone_reservations' => ['nullable', 'string', 'max:50'],
            'web'                => ['nullable', 'url'],
            'address'            => ['nullable', 'string', 'max:255'],
            'address2'           => ['nullable', 'string', 'max:255'],
            'landmark'           => ['nullable', 'string', 'max:255'],
            'postal_code'        => ['nullable', 'string', 'max:20'],
            'city_id'            => ['nullable', 'integer'],
            'state_id'           => ['nullable', 'integer'],
            'country_id'         => ['nullable', 'string', 'max:10'],
            'currency_id'        => ['nullable', 'string', 'max:4'],
            'language_id'        => ['nullable', 'string', 'max:4'],
            'timezone'           => ['nullable', 'timezone'],
            // Faltaban: el PUT descartaba las coordenadas en silencio.
            'latitude'           => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'          => ['nullable', 'numeric', 'between:-180,180'],
            'file_number'        => ['nullable', 'string', 'max:50'],
            'tax_identification' => ['nullable', 'string', 'max:50'],
            'channel_code'       => ['nullable', 'string', 'max:50'],
            'bank_data'          => ['nullable', 'string'],
            'comment'            => ['nullable', 'string'],
            'enabled'            => ['nullable', 'boolean'],
            'active'             => ['nullable', 'boolean'],
            'test'               => ['nullable', 'boolean'],
            'expiration'         => ['nullable', 'date'],
        ];
    }
}
