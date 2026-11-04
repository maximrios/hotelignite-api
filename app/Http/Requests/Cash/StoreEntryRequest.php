<?php

namespace App\Http\Requests\Cash;

use App\Models\CashEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Movimiento de caja. Montos en centavos, siempre positivos: el sentido lo da la categoría. */
class StoreEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accommodation_id' => ['required', 'integer'],
            'category_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'currency' => ['required', Rule::in(CashEntry::CURRENCIES)],
            'method' => ['required', Rule::in(CashEntry::METHODS)],
            'reservation_id' => ['nullable', 'string', 'max:36'],
            'reservation_amount' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'concept' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:60'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'El monto tiene que ser mayor a cero.',
            'category_id.required' => 'Elegí una categoría.',
        ];
    }
}
