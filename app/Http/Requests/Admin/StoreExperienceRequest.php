<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Alta por el staff: puede asignarla a un client (`client_id`). */
class StoreExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...ExperienceRules::for(null, $this->input('source')),
            'client_id' => ExperienceRules::clientId(),
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => ExperienceRules::after($validator, $this->all(), null)];
    }
}
