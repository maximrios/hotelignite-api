<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchChannelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * `has_client` filtra por si el canal tiene un Client detrás: el PMS lo usa
     * en `false` para armar la vidriera de "disponibles para conectar", donde
     * los canales de padrón no deben aparecer.
     */
    public function rules()
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'business_type' => ['sometimes', Rule::in(['direct', 'ota', 'agency', 'gds', 'corporate', 'tour_operator', 'metasearch'])],
            'connection_type' => ['sometimes', Rule::in(['manual', 'api', 'ical', 'gds', 'email'])],
            'enabled' => ['sometimes', 'boolean'],
            'ota' => ['sometimes', 'boolean'],
            'has_client' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
