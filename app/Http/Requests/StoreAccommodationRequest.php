<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccommodationRequest extends FormRequest
{
    
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name'               => ['required', 'string', 'min:3', 'max:255'],
            'account_id'         => ['nullable', 'integer', 'exists:accounts,id'],
            'plan_id'            => ['nullable', 'integer', 'exists:plans,id'],
            'type_id'            => ['nullable', 'integer'],
            'email'              => ['nullable', 'email'],
            'phone'              => ['nullable', 'string', 'max:50'],
            'email_reservations' => ['nullable', 'email'],
            'phone_reservations' => ['nullable', 'string', 'max:50'],
            'web'                => ['nullable', 'url'],
            'address'            => ['nullable', 'string', 'max:255'],
            'postal_code'        => ['nullable', 'string', 'max:20'],
            'city_id'            => ['nullable', 'integer'],
            'state_id'           => ['nullable', 'integer'],
            'country_id'         => ['nullable', 'string', 'max:10'],
            'currency_id'        => ['nullable', 'string', 'max:4'],
            'enabled'            => ['nullable', 'boolean'],
        ];
    }
}
