<?php

namespace App\Http\Requests\Admin;

use App\Models\Experience;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reglas compartidas por el alta y la edición de una experiencia.
 *
 * Como en `EventRules`, las que cruzan campos van en `after()` sobre lo enviado
 * **combinado con lo guardado**: una edición parcial que sólo manda `group_max`
 * también tiene que respetar el `group_min` existente.
 *
 * `client_id` (en qué portal sale) lo agrega sólo la request del staff: el
 * client nunca lo elige, sale del token.
 */
final class ExperienceRules
{
    /** @return array<string, array<int, mixed>> */
    public static function for(?Experience $experience, ?string $source): array
    {
        return [
            'experience_category_id' => ['required', 'integer', Rule::exists('experience_categories', 'id')],
            'departure_city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
            'city_ids' => ['nullable', 'array', 'max:30'],
            'city_ids.*' => ['integer', 'distinct', Rule::exists('cities', 'id')],
            'point_of_interest_id' => [
                'nullable', 'integer',
                Rule::exists('points_of_interest', 'id')->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255', 'alpha_dash',
                Rule::unique('experiences', 'slug')->ignore($experience?->id),
            ],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:10000'],
            'duration_value' => ['nullable', 'numeric', 'min:0.5', 'max:999', 'required_with:duration_unit'],
            'duration_unit' => ['nullable', Rule::in(Experience::DURATION_UNITS), 'required_with:duration_value'],
            'difficulty' => ['nullable', Rule::in(Experience::DIFFICULTIES)],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:99'],
            'group_min' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'group_max' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'languages' => ['nullable', 'array', 'max:10'],
            'languages.*' => ['string', 'distinct', 'regex:/^[a-z]{2}$/'],
            'includes' => ['nullable', 'array', 'max:30'],
            'includes.*' => ['nullable', 'string', 'max:200'],
            'excludes' => ['nullable', 'array', 'max:30'],
            'excludes.*' => ['nullable', 'string', 'max:200'],
            'what_to_bring' => ['nullable', 'string', 'max:2000'],
            'meeting_point_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'price_type' => [Rule::in(Experience::PRICE_TYPES)],
            'price_from' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'price_unit' => [Rule::in(Experience::PRICE_UNITS)],
            'operating_days' => ['nullable', 'array', 'max:7'],
            'operating_days.*' => ['integer', 'between:1,7', 'distinct'],
            'season_months' => ['nullable', 'array', 'max:12'],
            'season_months.*' => ['integer', 'between:1,12', 'distinct'],
            'operator_name' => ['nullable', 'string', 'max:255'],
            'operator_email' => ['nullable', 'email', 'max:255'],
            'operator_phone' => ['nullable', 'string', 'max:50'],
            'booking_url' => ['nullable', 'url:https,http', 'max:255'],
            'links' => ['nullable', 'array', 'max:10'],
            'links.*.type' => ['required', 'string', Rule::in(array_keys(Experience::LINK_DOMAINS))],
            'links.*.url' => ['required', 'url:https,http', 'max:2048'],
            'status' => [Rule::in(Experience::STATUSES)],
            'is_featured' => ['boolean'],
            'source' => [Rule::in(Experience::SOURCES)],
            'external_id' => [
                'nullable', 'string', 'max:255',
                Rule::unique('experiences', 'external_id')
                    ->where('source', $source ?? $experience?->source ?? 'manual')
                    ->ignore($experience?->id),
            ],
        ];
    }

    /**
     * Validaciones entre campos, sobre lo enviado combinado con lo guardado.
     *
     * @param  array<string, mixed>  $input
     */
    public static function after(Validator $validator, array $input, ?Experience $experience): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $value = fn (string $key) => array_key_exists($key, $input) ? $input[$key] : $experience?->getAttribute($key);

        $min = $value('group_min');
        $max = $value('group_max');
        if (is_numeric($min) && is_numeric($max) && (int) $max < (int) $min) {
            $validator->errors()->add('group_max', 'El cupo máximo no puede ser menor que el mínimo.');
        }

        $duration = $value('duration_value');
        if ($value('duration_unit') === 'days' && is_numeric($duration) && floor((float) $duration) !== (float) $duration) {
            $validator->errors()->add('duration_value', 'Una duración en días tiene que ser un número entero.');
        }

        foreach ((array) ($input['links'] ?? []) as $i => $link) {
            if (! is_array($link) || ! EventRules::linkMatchesNetwork((string) ($link['type'] ?? ''), (string) ($link['url'] ?? ''))) {
                $validator->errors()->add("links.{$i}.url", 'La URL no corresponde a la red indicada.');
            }
        }
    }

    /**
     * `client_id`: a qué client (portal) se asigna. Sólo para el staff.
     *
     * @return array<int, mixed>
     */
    public static function clientId(): array
    {
        return ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')];
    }
}
