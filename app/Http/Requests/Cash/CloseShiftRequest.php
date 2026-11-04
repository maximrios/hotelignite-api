<?php

namespace App\Http\Requests\Cash;

use App\Models\CashEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cierre de caja: efectivo contado por moneda, en centavos. */
class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'counted' => ['required', 'array', 'min:1'],
            'counted.*.currency' => ['required', 'distinct', Rule::in(CashEntry::CURRENCIES)],
            'counted.*.amount' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
