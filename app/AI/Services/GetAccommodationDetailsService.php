<?php

namespace App\AI\Services;

use App\AI\Exceptions\AiToolException;
use App\AI\Mappers\AccommodationMapper;
use App\AI\Support\AiContext;
use App\AI\Support\RoomTypeResolver;
use App\Models\Accommodation;
use App\Models\AccommodationPolicy;
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

    public function __construct(private readonly RoomTypeResolver $rooms) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(AiContext $ctx, string $slug): array
    {
        $accommodation = Accommodation::visibleTo($ctx->actor)
            ->where('enabled', 1)
            ->where('slug', $slug)
            ->with([...AccommodationMapper::EAGER, 'roomTypes.descriptions'])
            ->first();

        if ($accommodation === null) {
            throw new AiToolException("No encontré el alojamiento \"{$slug}\". Usá search_accommodations para obtener el slug correcto.");
        }

        $text = AccommodationMapper::fullDescription($accommodation, $ctx->language);

        return [
            'id' => (int) $accommodation->id,
            'slug' => (string) $accommodation->slug,
            'name' => (string) $accommodation->name,
            'type' => $accommodation->type?->name,
            'city' => $accommodation->city?->name,
            'state' => $accommodation->state?->name ?? $accommodation->city?->state?->name,
            'address' => $accommodation->address,
            'description' => $text !== null ? Str::limit($text, self::DESCRIPTION_MAX_CHARS) : null,
            'services' => AccommodationMapper::serviceNames($accommodation),
            'rooms' => array_map(fn (array $room) => [
                'name' => $room['name'],
                'max_occupancy' => $room['max_occupancy'],
                'occupancy_source' => $room['source'],
            ], $this->rooms->forAccommodation($accommodation, $ctx->language)),
            'policies' => $this->policies($accommodation, $ctx->language),
            'images' => $accommodation->images
                ->take(self::MAX_IMAGES)
                ->map(fn ($image) => ['url' => $image->url, 'alt' => $image->alt])
                ->values()
                ->all(),
        ];
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
