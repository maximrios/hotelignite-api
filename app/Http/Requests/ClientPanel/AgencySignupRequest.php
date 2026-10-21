<?php

namespace App\Http\Requests\ClientPanel;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Registro de una agencia en el portal. Público: lo que llega acá crea un
 * client y un user, así que se valida estricto y se normaliza antes de guardar.
 */
class AgencySignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            // El CUIT se guarda siempre como XX-XXXXXXXX-X, se tipee como se tipee.
            'tax_id' => self::formatCuit((string) $this->input('tax_id')),
        ]);
    }

    public function rules(): array
    {
        return [
            'agency_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['required', 'string', 'regex:/^\d{2}-\d{8}-\d$/'],
            'license_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'tax_id.regex' => 'El CUIT tiene que tener 11 dígitos.',
            'email.unique' => 'Ya hay una cuenta con ese email. Iniciá sesión o recuperá tu contraseña.',
            'password.min' => 'La contraseña tiene que tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Una agencia, un registro: el segundo con el mismo CUIT casi
                // siempre es la misma agencia con otro empleado.
                if (! $validator->errors()->has('tax_id')
                    && Client::where('tax_id', $this->input('tax_id'))->exists()) {
                    $validator->errors()->add(
                        'tax_id',
                        'Ya hay una agencia registrada con ese CUIT. Pedile acceso a quien la registró o escribinos.'
                    );
                }
            },
        ];
    }

    private static function formatCuit(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);

        return strlen($digits) === 11
            ? substr($digits, 0, 2).'-'.substr($digits, 2, 8).'-'.substr($digits, 10)
            : trim($value);
    }
}
