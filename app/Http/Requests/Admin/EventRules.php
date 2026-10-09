<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reglas compartidas por el alta y la edición de un evento (admin y client-panel).
 *
 * Las reglas que cruzan campos (fin ≥ inicio, días de la semana, precio, dominio
 * de cada red) van en `after()` sobre los datos **combinados con el evento
 * existente**: una edición parcial que sólo manda `end_date` también tiene que
 * respetar el `start_date` guardado.
 */
final class EventRules
{
    /** @return array<string, array<int, mixed>> */
    public static function for(?Event $event, ?string $source): array
    {
        return [
            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
            'event_category_id' => ['required', 'integer', Rule::exists('event_categories', 'id')],
            'point_of_interest_id' => [
                'nullable', 'integer',
                Rule::exists('points_of_interest', 'id')->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255', 'alpha_dash',
                Rule::unique('events', 'slug')->ignore($event?->id),
            ],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:10000'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'recurrence' => [Rule::in(Event::RECURRENCES)],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'status' => [Rule::in(Event::STATUSES)],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'price_type' => [Rule::in(Event::PRICE_TYPES)],
            'price_from' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'price_to' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'ticket_url' => ['nullable', 'url', 'max:255'],
            'organizer_name' => ['nullable', 'string', 'max:255'],
            'organizer_email' => ['nullable', 'email', 'max:255'],
            'organizer_phone' => ['nullable', 'string', 'max:50'],
            'links' => ['nullable', 'array', 'max:10'],
            'links.*.type' => ['required', 'string', Rule::in(array_keys(Event::LINK_DOMAINS))],
            'links.*.url' => ['required', 'url:https,http', 'max:2048'],
            'is_featured' => ['boolean'],
            'enabled' => ['boolean'],
            'source' => [Rule::in(Event::SOURCES)],
            'external_id' => [
                'nullable', 'string', 'max:255',
                Rule::unique('events', 'external_id')
                    ->where('source', $source ?? $event?->source ?? 'manual')
                    ->ignore($event?->id),
            ],
        ];
    }

    /**
     * Validaciones entre campos, sobre lo enviado combinado con lo guardado.
     *
     * @param  array<string, mixed>  $input
     */
    public static function after(Validator $validator, array $input, ?Event $event): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $value = fn (string $key) => array_key_exists($key, $input) ? $input[$key] : self::stored($event, $key);

        $start = $value('start_date');
        $end = $value('end_date');
        if (is_string($start) && is_string($end) && $end < $start) {
            $validator->errors()->add('end_date', 'La fecha de fin no puede ser anterior a la de inicio.');
        }

        if ($value('recurrence') === 'weekly') {
            $days = $value('weekdays');
            if (! is_array($days) || $days === []) {
                $validator->errors()->add('weekdays', 'Un evento semanal necesita al menos un día de la semana.');
            }
        }

        $from = $value('price_from');
        $to = $value('price_to');
        if (is_numeric($from) && is_numeric($to) && (float) $to < (float) $from) {
            $validator->errors()->add('price_to', 'El precio máximo no puede ser menor que el mínimo.');
        }

        foreach ((array) ($input['links'] ?? []) as $i => $link) {
            if (! is_array($link) || ! self::linkMatchesNetwork((string) ($link['type'] ?? ''), (string) ($link['url'] ?? ''))) {
                $validator->errors()->add("links.{$i}.url", 'La URL no corresponde a la red indicada.');
            }
        }
    }

    /** `website` acepta cualquier dominio; el resto, el suyo o un subdominio. */
    public static function linkMatchesNetwork(string $type, string $url): bool
    {
        $domains = Event::LINK_DOMAINS[$type] ?? null;
        if ($domains === null) {
            return false;
        }
        if ($domains === []) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private static function stored(?Event $event, string $key): mixed
    {
        if ($event === null) {
            return null;
        }

        $raw = $event->getAttribute($key);

        return $raw instanceof \DateTimeInterface ? $raw->format('Y-m-d') : $raw;
    }
}
