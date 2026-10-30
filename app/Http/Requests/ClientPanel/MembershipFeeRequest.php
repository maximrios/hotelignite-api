<?php

namespace App\Http\Requests\ClientPanel;

use Illuminate\Foundation\Http\FormRequest;

/** Alta y edición de una categoría de cuota. Monto en centavos. */
class MembershipFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `client.user`
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            'amount' => [$required, 'integer', 'min:1', 'max:100000000000'],
            // Hasta 28: todos los meses lo tienen.
            'due_day' => ['sometimes', 'integer', 'min:1', 'max:28'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
