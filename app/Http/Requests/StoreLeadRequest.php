<?php

namespace App\Http\Requests;

use App\Models\Lead;
use App\Rules\Turnstile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Formulario de la landing (empezar gratis / pedir demo). Público y anónimo:
 * se valida corto, con un honeypot (`website`) que un humano no ve y, si hay
 * `services.turnstile.secret_key`, con el token de Cloudflare Turnstile.
 */
class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Lead::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            // Un hotelero chico vive en WhatsApp: es el canal para el alta asistida.
            'phone' => ['required', 'string', 'max:50'],
            'accommodation_name' => ['required_if:type,'.Lead::TYPE_SIGNUP, 'nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'rooms' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['prohibited'],
            'cf-turnstile-response' => filled(config('services.turnstile.secret_key'))
                ? ['bail', 'required', 'string', 'max:2048', new Turnstile($this->ip())]
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'accommodation_name.required_if' => 'Contanos cómo se llama tu alojamiento.',
            'phone.required' => 'Dejanos un teléfono o WhatsApp para contactarte.',
            'cf-turnstile-response.required' => 'Esperá a que termine la verificación de seguridad y volvé a enviar.',
        ];
    }
}
