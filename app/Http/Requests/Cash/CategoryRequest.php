<?php

namespace App\Http\Requests\Cash;

use App\Models\CashCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta (POST) y edición (PATCH) de una categoría de caja. */
class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->isMethod('post')) {
            return [
                'accommodation_id' => ['required', 'integer'],
                'name' => ['required', 'string', 'min:2', 'max:80'],
                'direction' => ['required', Rule::in([CashCategory::IN, CashCategory::OUT])],
            ];
        }

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
