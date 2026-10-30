<?php

namespace App\Http\Requests\ClientPanel;

use App\Models\MemberLedgerEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asiento manual en la cuenta corriente de un socio. El monto llega en
 * centavos y siempre positivo: el signo lo pone el tipo.
 */
class LedgerEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gateado por el middleware `client.user`
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in([
                MemberLedgerEntry::KIND_PAYMENT,
                MemberLedgerEntry::KIND_CHARGE,
                MemberLedgerEntry::KIND_CREDIT,
            ])],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:occurred_on'],
            'concept' => ['nullable', 'string', 'max:160'],
            'method' => ['required_if:kind,payment', 'nullable', Rule::in(MemberLedgerEntry::METHODS)],
            'reference' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'occurred_on.before_or_equal' => 'La fecha no puede ser futura.',
            'method.required_if' => 'Indicá cómo pagó.',
        ];
    }
}
