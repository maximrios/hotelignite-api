<?php

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

/** Adicionales por persona y descuento de uso single de un tipo (por noche). */
class UpdateOccupancyPricingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'extra_adult_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'extra_child_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'single_use_discount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ];
    }
}
