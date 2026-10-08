<?php

namespace App\Http\Requests\Admin;

use App\Models\PoiCategory;
use Illuminate\Validation\Rule;

/**
 * Reglas del alta y la edición de una categoría de POI. Las que dependen del
 * estado de la categoría (tiene hijas, tiene puntos) las aplica el repositorio.
 */
final class PoiCategoryRules
{
    /** @return array<string, array<int, mixed>> */
    public static function for(?PoiCategory $category): array
    {
        return [
            // Dos niveles: el padre tiene que ser una raíz, y no ella misma.
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('poi_categories', 'id')->whereNull('parent_id'),
                Rule::notIn(array_filter([$category?->id])),
            ],
            'slug' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('poi_categories', 'slug')->ignore($category?->id),
            ],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['integer', 'min:0', 'max:1000'],
            'default_radius_m' => ['nullable', 'integer', 'min:50', 'max:300000'],
            'nearest_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'enabled' => ['boolean'],
        ];
    }
}
