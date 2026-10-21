<?php

namespace App\Http\Requests\Admin;

use App\Models\Experience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Edición parcial (PUT y PATCH) por el staff: sólo se validan los campos presentes. */
class UpdateExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            ...ExperienceRules::for($this->experience(), $this->input('source')),
            'client_id' => ExperienceRules::clientId(),
        ];

        return array_map(fn (array $rule) => ['sometimes', ...$rule], $rules);
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => ExperienceRules::after($validator, $this->all(), $this->experience())];
    }

    private function experience(): ?Experience
    {
        $experience = $this->route('experience');

        return $experience instanceof Experience ? $experience : null;
    }
}
