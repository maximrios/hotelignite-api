<?php

namespace App\Http\Requests\Admin;

use App\Models\Experience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchExperienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `client_id`: un id, o `none` para las del staff sin asignar. `city_id`
     * matchea la ciudad de salida o cualquiera de los destinos que recorre.
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'client_id' => ['sometimes', 'regex:/^(none|\d+)$/'],
            'city_id' => ['sometimes', 'integer'],
            'category_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(Experience::STATUSES)],
            'difficulty' => ['sometimes', Rule::in(Experience::DIFFICULTIES)],
            'duration_kind' => ['sometimes', Rule::in(Experience::DURATION_KINDS)],
            'featured' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
