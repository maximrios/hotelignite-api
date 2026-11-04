<?php

namespace App\Http\Requests\Cash;

use App\Models\CashEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Apertura de caja: fondo inicial por moneda, en centavos. */
class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la tenencia la valida CashRepository
    }

    public function rules(): array
    {
        return [
            'accommodation_id' => ['required', 'integer'],
            'balances' => ['sometimes', 'array'],
            'balances.*.currency' => ['required', 'distinct', Rule::in(CashEntry::CURRENCIES)],
            'balances.*.amount' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ];
    }
}
