<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Edición masiva de la grilla de tarifas (rango inclusive × días × tipos). */
class BulkRatesRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => ['required', 'integer'],
            'room_type_ids' => ['required', 'array', 'min:1'],
            'room_type_ids.*' => ['integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$this->maxTo()],
            // 0 = domingo … 6 = sábado (Carbon::dayOfWeek).
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'price_mode' => ['required', Rule::in(['none', 'set', 'percent', 'amount'])],
            'price_value' => ['required_unless:price_mode,none', 'nullable', 'numeric', 'min:-100000000', 'max:100000000'],
            'min_stay' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:30'],
            'stop_sell' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /** Hasta un año desde el inicio del rango. */
    private function maxTo(): string
    {
        $from = $this->input('from');

        return is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            ? date('Y-m-d', strtotime($from.' +366 days'))
            : '9999-12-31';
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('price_mode') === 'set' && (float) $this->input('price_value') <= 0) {
                $v->errors()->add('price_value', 'El precio tiene que ser mayor a 0.');
            }
            if ($this->input('price_mode') === 'percent' && (float) $this->input('price_value') <= -100) {
                $v->errors()->add('price_value', 'No se puede bajar 100% o más.');
            }
            if ($this->input('price_mode') === 'none' && $this->input('min_stay') === null && $this->input('stop_sell') === null) {
                $v->errors()->add('price_mode', 'No hay nada para cambiar.');
            }
        });
    }

    public function messages()
    {
        return [
            'room_type_ids.required' => 'Elegí al menos un tipo.',
            'weekdays.required' => 'Elegí al menos un día de la semana.',
            'to.after_or_equal' => 'El hasta tiene que ser igual o posterior al desde.',
            'to.before_or_equal' => 'El rango puede ser de hasta un año.',
            'price_value.required_unless' => 'Ingresá el valor.',
        ];
    }
}
