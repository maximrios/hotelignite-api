<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reorden de una galería (hotel o tipo de habitación). Acepta la lista de ids
 * en el orden final (`ids`, lo que manda el PMS) o el formato viejo
 * `images: [{id, order}]`. La primera imagen es la portada.
 */
class ReorderAccommodationImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required_without:images', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'images' => ['required_without:ids', 'array', 'min:1'],
            'images.*.id' => ['required', 'integer'],
            'images.*.order' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<int, int> id => orden */
    public function orders(): array
    {
        $ids = $this->validated('ids');
        if (is_array($ids)) {
            return collect($ids)->values()->mapWithKeys(fn ($id, $i) => [(int) $id => $i])->all();
        }

        return collect($this->validated('images'))
            ->mapWithKeys(fn (array $row) => [(int) $row['id'] => (int) $row['order']])
            ->all();
    }
}
