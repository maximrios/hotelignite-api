<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'name'            => ['sometimes', 'string', 'min:2', 'max:255'],
            'plan_id'         => ['nullable', 'integer', 'exists:plans,id'],
            'account_type_id' => ['nullable', 'integer', 'exists:account_types,id'],
            'first_name'      => ['nullable', 'string', 'max:100'],
            'last_name'       => ['nullable', 'string', 'max:100'],
            'email'           => ['nullable', 'email', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'active'          => ['nullable', 'boolean'],
            'test'            => ['nullable', 'boolean'],
            'expiration_date' => ['nullable', 'date'],
            'comments'        => ['nullable', 'string'],
            'agreement'       => ['nullable', 'boolean'],
        ];
    }
}
