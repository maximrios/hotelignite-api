<?php

namespace App\Http\Requests\Cash;

use App\Models\CashEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros del libro de caja (listado y CSV). */
class EntryFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accommodation_id' => ['required', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'method' => ['nullable', Rule::in(CashEntry::METHODS)],
            'currency' => ['nullable', Rule::in(CashEntry::CURRENCIES)],
            'category_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'reservation_id' => ['nullable', 'string', 'max:36'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
