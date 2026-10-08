<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NearbyPointsRequest extends FormRequest
{
    /** La tenencia la resuelve el controller con `AccommodationPolicy@view`. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit_per_group' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }
}
