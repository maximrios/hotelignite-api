<?php

namespace App\AI\Services;

use App\AI\Exceptions\AiToolException;
use App\AI\Mappers\AccommodationMapper;
use App\AI\Support\AiContext;
use App\AI\Support\RoomTypeResolver;
use App\Models\Accommodation;
use App\Models\AccommodationPolicy;
use App\Models\AccommodationRatePolicy;
use App\Models\Extra;
use App\Models\RoomType;
use App\Repositories\PointOfInterestRepository;
use App\Support\BookingMailData;
use Illuminate\Support\Str;

/**
 * Herramienta `get_accommodation_details`: la ficha completa de un alojamiento
 * para responder preguntas puntuales (servicios, habitaciones, políticas).
 * No expone email ni teléfono del prestador: la consulta va por el portal.
 */
final class GetAccommodationDetailsService
{
    private const DESCRIPTION_MAX_CHARS = 1500;

    private const MAX_IMAGES = 3;

    private const ROOM_DESCRIPTION_MAX_CHARS = 300;

    /** Cercanos por categoría raíz: el modelo necesita referencias, no el mapa entero. */
    private const NEARBY_PER_GROUP = 3;

    /** Relaciones de la ficha además de las de `AccommodationMapper::EAGER`. */
    private const DETAIL_EAGER = ['roomTypes.descriptions', 'roomTypes.beds', 'roomTypes.services', 'ratePolicies', 'extras'];

    private const BED_NAMES = [
        'king' => 'king',
        'queen' => 'queen',
        'double' => 'matrimonial',
        'twin' => 'twin',
        'single' => 'individual',
        'sofa_bed' => 'sofá cama',
        'bunk_bed' => 'cucheta',
        'crib' => 'cuna',
    ];

    private const RATE_TYPE_NAMES = [
        'flexible' => 'Tarifa flexible',
        'moderate' => 'Tarifa moderada',
        'strict' => 'Tarifa estricta',
        'non_refundable' => 'Tarifa no reembolsable',
    ];

    public function __construct(
        private readonly RoomTypeResolver $rooms,
        private readonly PointOfInterestRepository $pointsOfInterest,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(AiContext $ctx, string $slug): array
    {
        $accommodation = Accommodation::visibleTo($ctx->actor)
            ->where('enabled', 1)
            ->where('slug', $slug)
            ->with([...AccommodationMapper::EAGER, ...self::DETAIL_EAGER])
            ->first();

        if ($accommodation === null) {
            throw new AiToolException("No encontré el alojamiento \"{$slug}\". Usá search_accommodations para obtener el slug correcto.");
        }

        return $this->render($ctx, $accommodation);
    }

    /**
     * La ficha del alojamiento fijado en el contexto (MCP del alojamiento). Igual
     * pasa por `visibleTo`: la key puede haber perdido el alojamiento a mitad de
     * la sesión.
     *
     * @return array<string, mixed>
     */
    public function handleCurrent(AiContext $ctx): array
    {
        $accommodation = $ctx->accommodation === null ? null : Accommodation::visibleTo($ctx->actor)
            ->where('enabled', 1)
            ->with([...AccommodationMapper::EAGER, ...self::DETAIL_EAGER])
            ->find($ctx->accommodation->id);

        if ($accommodation === null) {
            throw new AiToolException('El alojamiento no está disponible.');
        }

        return $this->render($ctx, $accommodation);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(AiContext $ctx, Accommodation $accommodation): array
    {
        $text = AccommodationMapper::fullDescription($accommodation, $ctx->language);
        $nearby = $this->pointsOfInterest->nearby($accommodation, self::NEARBY_PER_GROUP);
        $roomTypes = $accommodation->roomTypes->keyBy('id');

        return [
            'id' => (int) $accommodation->id,
            'slug' => (string) $accommodation->slug,
            'name' => (string) $accommodation->name,
            'type' => $accommodation->type?->name,
            'stars' => $accommodation->stars ? (int) $accommodation->stars : null,
            'city' => $accommodation->city?->name,
            'state' => $accommodation->state?->name ?? $accommodation->city?->state?->name,
            'address' => $accommodation->address,
            'location' => $this->location($accommodation, $nearby['origin']),
            'description' => $text !== null ? Str::limit($text, self::DESCRIPTION_MAX_CHARS) : null,
            'services' => AccommodationMapper::serviceNames($accommodation),
            'rooms' => array_map(fn (array $room) => [
                'name' => $room['name'],
                'max_occupancy' => $room['max_occupancy'],
                'occupancy_source' => $room['source'],
                ...$this->roomDetails($room['id'] !== null ? $roomTypes->get($room['id']) : null, $ctx->language),
            ], $this->rooms->forAccommodation($accommodation, $ctx->language)),
            'policies' => $this->policies($accommodation, $ctx->language),
            'cancellation' => $this->cancellation($accommodation),
            'extras' => $this->extras($accommodation),
            'nearby' => $this->nearby($nearby['groups']),
            'images' => $accommodation->images
                ->take(self::MAX_IMAGES)
                ->map(fn ($image) => ['url' => $image->url, 'alt' => $image->alt])
                ->values()
                ->all(),
        ];
    }

    /**
     * Extras que vende el hotel (cochera, desayuno, traslado…), sólo los activos. Distintos
     * de `services`: aquellos son lo que el lugar tiene; estos se pagan aparte. Se piden al
     * reservar en el motor; por acá sólo se informan.
     *
     * @return list<array<string, mixed>>
     */
    private function extras(Accommodation $accommodation): array
    {
        $currency = strlen(trim((string) $accommodation->currency_id)) === 3 ? strtoupper(trim((string) $accommodation->currency_id)) : 'ARS';

        return $accommodation->extras
            ->where('enabled', true)
            ->map(fn (Extra $extra) => [
                'name' => $extra->name,
                'description' => $extra->description,
                'price' => $extra->price / 100,
                'currency' => $currency,
                'charge' => BookingMailData::chargeMode($extra->charge_mode),
                'free' => $extra->price === 0,
                'price_includes_tax' => $extra->price_includes_tax,
                'max_per_booking' => $extra->max_quantity,
                'subject_to_availability' => $extra->requires_confirmation,
                'note' => $extra->template === Extra::TEMPLATE_BREAKFAST
                    ? 'Si la tarifa elegida ya incluye desayuno, no se cobra aparte.'
                    : null,
                'image' => $extra->image_url,
            ])
            ->values()
            ->all();
    }

    /**
     * Políticas estructuradas (`accommodation_policies`) como lista legible.
     * Vacía si el alojamiento no las cargó: el agente invita a consultar.
     *
     * @return list<array{name: string, description: string}>
     */
    private function policies(Accommodation $accommodation, string $language): array
    {
        $policy = AccommodationPolicy::where('accommodation_id', $accommodation->id)
            ->with('translations')
            ->first();

        if ($policy === null) {
            return [];
        }

        $items = [];

        if ($policy->checkin_from || $policy->checkin_to) {
            $items[] = ['name' => 'Check-in', 'description' => $this->range($policy->checkin_from, $policy->checkin_to)];
        }

        if ($policy->checkout_from || $policy->checkout_to) {
            $items[] = ['name' => 'Check-out', 'description' => $this->range($policy->checkout_from, $policy->checkout_to)];
        }

        $items[] = ['name' => 'Niños', 'description' => $policy->allow_children
            ? ($policy->children_max_age ? "Se aceptan (hasta {$policy->children_max_age} años se consideran niños)" : 'Se aceptan')
            : 'No se aceptan'];
        $items[] = ['name' => 'Mascotas', 'description' => $policy->allow_pets ? 'Se aceptan' : 'No se aceptan'];
        $items[] = ['name' => 'Fumar', 'description' => $policy->allow_smoking ? 'Permitido' : 'No permitido'];
        $items[] = ['name' => 'Fiestas y eventos', 'description' => $policy->allow_parties ? 'Permitidos' : 'No permitidos'];

        if ($policy->min_age) {
            $items[] = ['name' => 'Edad mínima del titular', 'description' => "{$policy->min_age} años"];
        }

        $payments = array_keys(array_filter([
            'tarjeta' => $policy->payment_card,
            'efectivo' => $policy->payment_cash,
            'transferencia' => $policy->payment_transfer,
            'cripto' => $policy->payment_crypto,
        ]));

        if ($payments !== []) {
            $items[] = ['name' => 'Medios de pago', 'description' => implode(', ', $payments)];
        }

        $rules = $policy->translations->firstWhere('language_id', $language)?->house_rules
            ?? $policy->translations->first()?->house_rules;

        if ($rules) {
            $items[] = ['name' => 'Normas de la casa', 'description' => Str::limit(AccommodationMapper::plain($rules), 500)];
        }

        return $items;
    }

    /**
     * Cómo llegar. Las coordenadas salen de `location` (la columna validada que
     * usa PostGIS), no de `latitude`/`longitude`, que son varchar legacy con
     * basura en algunas fichas.
     *
     * @param  array{latitude: float, longitude: float}|null  $origin
     * @return array{landmark: ?string, postal_code: ?string, maps_url: ?string}
     */
    private function location(Accommodation $accommodation, ?array $origin): array
    {
        $landmark = trim((string) $accommodation->landmark);
        $postalCode = trim((string) $accommodation->postal_code);

        return [
            'landmark' => $landmark !== '' ? $landmark : null,
            'postal_code' => $postalCode !== '' ? $postalCode : null,
            'maps_url' => $origin !== null
                ? sprintf('https://www.google.com/maps/search/?api=1&query=%.6f,%.6f', $origin['latitude'], $origin['longitude'])
                : null,
        ];
    }

    /**
     * Lo que distingue a un tipo de habitación de otro cuando el huésped
     * pregunta "¿qué cama tiene?" o "¿tiene aire?". Vacío en los simulados.
     *
     * @return array<string, mixed>
     */
    private function roomDetails(?RoomType $roomType, string $language): array
    {
        if ($roomType === null) {
            return [];
        }

        $translation = $roomType->descriptions->firstWhere('language_id', $language)
            ?? $roomType->descriptions->first();
        $description = AccommodationMapper::plain((string) $translation?->description);

        return [
            'size_m2' => $roomType->size ? (int) $roomType->size : null,
            'max_adults' => $roomType->max_adults ? (int) $roomType->max_adults : null,
            'max_children' => $roomType->max_children !== null ? (int) $roomType->max_children : null,
            'beds' => $roomType->beds
                ->map(fn ($bed) => sprintf('%d %s', (int) $bed->quantity, self::BED_NAMES[$bed->type] ?? $bed->type))
                ->values()
                ->all(),
            'services' => $roomType->services->pluck('name')->filter()->values()->all(),
            'description' => $description !== '' ? Str::limit($description, self::ROOM_DESCRIPTION_MAX_CHARS) : null,
        ];
    }

    /**
     * Condiciones de cancelación por tipo de tarifa, en texto. Vacía si el
     * alojamiento no las cargó: el agente no puede suponer que es flexible.
     *
     * @return list<array{rate: string, description: string}>
     */
    private function cancellation(Accommodation $accommodation): array
    {
        return $accommodation->ratePolicies
            ->map(fn (AccommodationRatePolicy $policy) => [
                'rate' => self::RATE_TYPE_NAMES[$policy->rate_type] ?? (string) $policy->rate_type,
                'description' => $this->cancellationText($policy),
            ])
            ->values()
            ->all();
    }

    private function cancellationText(AccommodationRatePolicy $policy): string
    {
        if ($policy->rate_type === 'non_refundable') {
            $text = 'No admite cancelación sin cargo.';
        } elseif ($policy->cancel_days !== null) {
            $text = "Cancelación sin cargo hasta {$policy->cancel_days} días antes del check-in";
            $text .= $policy->cancel_penalty !== null
                ? sprintf('; después se cobra el %s%% de la reserva.', $this->percent($policy->cancel_penalty))
                : '.';
        } else {
            $text = 'Condiciones de cancelación a consultar con el alojamiento.';
        }

        if ($policy->no_show_penalty !== null) {
            $text .= sprintf(' Si no se presenta, se cobra el %s%%.', $this->percent($policy->no_show_penalty));
        }

        return $text;
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }

    /**
     * "En los alrededores", compacto: nombre, categoría y distancia. Sin
     * teléfono ni web: el agente da referencias, no reemplaza a un buscador.
     *
     * @param  list<array{category: mixed, items: list<mixed>}>  $groups
     * @return list<array{category: string, places: list<array{name: string, distance: string}>}>
     */
    private function nearby(array $groups): array
    {
        return array_map(fn (array $group) => [
            'category' => (string) $group['category']->resource->name,
            'places' => array_map(function ($item) {
                $poi = $item->resource;
                $meters = (float) $poi->distance_m;

                return [
                    'name' => (string) $poi->name,
                    'distance' => match (true) {
                        $poi->walk_min !== null => "{$poi->walk_min} min a pie",
                        $meters < 1000 => sprintf('%d m', (int) round($meters)),
                        default => sprintf('%s km', number_format($meters / 1000, 1, ',', '')),
                    },
                ];
            }, $group['items']),
        ], $groups);
    }

    private function range(?string $from, ?string $to): string
    {
        $from = $from ? substr($from, 0, 5) : null;
        $to = $to ? substr($to, 0, 5) : null;

        return match (true) {
            $from && $to => "de {$from} a {$to}",
            (bool) $from => "desde las {$from}",
            default => "hasta las {$to}",
        };
    }
}
