<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChannelRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'name'            => ['required', 'string', 'min:2', 'max:255'],
            'business_type'   => ['nullable', 'string', Rule::in(['direct', 'ota', 'agency', 'gds', 'corporate', 'tour_operator', 'metasearch'])],
            'connection_type' => ['nullable', 'string', Rule::in(['manual', 'api', 'ical', 'gds', 'email'])],
            'code'            => ['nullable', 'string', 'max:20', 'unique:channels,code'],
            'email'           => ['nullable', 'email', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'web'             => ['nullable', 'url', 'max:255'],
            'ota'             => ['nullable', 'boolean'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'enabled'         => ['nullable', 'boolean'],
        ];
    }
}
