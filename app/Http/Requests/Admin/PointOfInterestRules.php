<?php

namespace App\Http\Requests\Admin;

use App\Models\PointOfInterest;
use Illuminate\Validation\Rule;

/**
 * Reglas compartidas por el alta y la edición de un punto de interés. La
 * edición es parcial: cada regla lleva `sometimes` delante.
 */
final class PointOfInterestRules
{
    /** @return array<string, array<int, mixed>> */
    public static function for(?PointOfInterest $poi, ?string $source): array
    {
        return [
            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
            // Siempre una hoja: la raíz sólo agrupa y define el radio.
            'poi_category_id' => [
                'required', 'integer',
                Rule::exists('poi_categories', 'id')->whereNotNull('parent_id'),
            ],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255', 'alpha_dash',
                Rule::unique('points_of_interest', 'slug')->ignore($poi?->id),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            // Las dos o ninguna: media coordenada no es un punto.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'is_featured' => ['boolean'],
            'enabled' => ['boolean'],
            'source' => [Rule::in(PointOfInterest::SOURCES)],
            'external_id' => [
                'nullable', 'string', 'max:255',
                Rule::unique('points_of_interest', 'external_id')
                    ->where('source', $source ?? $poi?->source ?? 'manual')
                    ->ignore($poi?->id),
            ],
        ];
    }
}
