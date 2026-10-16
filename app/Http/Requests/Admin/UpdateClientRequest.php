<?php

namespace App\Http\Requests\Admin;

use App\Models\Client;
use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `platform`
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(Client::TYPES)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
            // Plan de client (no de hotelero) y datos fiscales para presupuesto y factura.
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')->where('audience', Plan::AUDIENCE_CLIENT)],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'iva_condition' => ['nullable', 'string', 'max:40'],
        ];
    }
}
