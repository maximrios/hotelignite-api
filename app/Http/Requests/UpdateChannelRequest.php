<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChannelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            // Ver la nota de StoreChannelRequest: columnas NOT NULL con default.
            'business_type' => ['sometimes', 'string', Rule::in(['direct', 'ota', 'agency', 'gds', 'corporate', 'tour_operator', 'metasearch'])],
            'connection_type' => ['sometimes', 'string', Rule::in(['manual', 'api', 'ical', 'gds', 'email'])],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('channels', 'code')->ignore($this->route('channel'))],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'web' => ['nullable', 'url', 'max:255'],
            'ota' => ['nullable', 'boolean'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
