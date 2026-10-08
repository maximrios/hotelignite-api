<?php

namespace App\Http\Requests\Admin;

use App\Models\PoiCategory;
use Illuminate\Foundation\Http\FormRequest;

/** Edición parcial (PUT y PATCH): sólo se validan los campos presentes. */
class UpdatePoiCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('category');

        $rules = PoiCategoryRules::for($category instanceof PoiCategory ? $category : null);

        return array_map(fn (array $rule) => ['sometimes', ...$rule], $rules);
    }
}
