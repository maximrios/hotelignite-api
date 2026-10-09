<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return EventRules::for(null, $this->input('source'));
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => EventRules::after($validator, $this->all(), null)];
    }
}
