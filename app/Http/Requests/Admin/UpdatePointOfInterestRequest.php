<?php

namespace App\Http\Requests\Admin;

use App\Models\PointOfInterest;
use Illuminate\Foundation\Http\FormRequest;

/** Edición parcial (PUT y PATCH): sólo se validan los campos presentes. */
class UpdatePointOfInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $poi = $this->route('poi');

        $rules = PointOfInterestRules::for(
            $poi instanceof PointOfInterest ? $poi : null,
            $this->input('source'),
        );

        return array_map(fn (array $rule) => ['sometimes', ...$rule], $rules);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return (new StorePointOfInterestRequest)->messages();
    }
}
