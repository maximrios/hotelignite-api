<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Plan de tarifa a nivel hotel. Los derivados se calculan desde la tarifa base
 * con un % o un monto por noche (negativo = descuento).
 */
class UpsertRatePlanRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'accommodation_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,6}$/'],
            'cancellation' => ['required', Rule::in(['flexible', 'moderate', 'strict', 'non_refundable'])],
            'meal_plan' => ['required', Rule::in(['ro', 'bb', 'hb', 'fb', 'ai'])],
            'enabled' => ['sometimes', 'boolean'],
            'adjustment_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'adjustment_value' => ['required_with:adjustment_type', 'nullable', 'numeric'],
            'room_type_ids' => ['sometimes', 'array'],
            'room_type_ids.*' => ['integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $value = (float) $this->input('adjustment_value');
            if ($this->input('adjustment_type') === 'percent' && ($value <= -100 || $value > 300)) {
                $v->errors()->add('adjustment_value', 'El porcentaje va de -99 a 300.');
            }
        });
    }

    public function messages()
    {
        return [
            'name.required' => 'Ingresá el nombre del plan.',
            'code.required' => 'Ingresá un código corto.',
            'code.regex' => 'El código va de 2 a 6 letras o números, sin espacios.',
            'adjustment_value.required_with' => 'Ingresá el valor del ajuste.',
        ];
    }
}
