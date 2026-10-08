<?php

namespace App\AI\Mappers;

use App\Models\Accommodation;
use App\Models\AccommodationDescription;
use Illuminate\Support\Str;

/**
 * Accommodation → arrays compactos para el modelo. No usa los Resources de la
 * API: el contrato del MCP no tiene que cambiar cuando cambian esos, y cada
 * token que se devuelve lo paga el modelo al leerlo.
 *
 * Espera las relaciones de `EAGER` cargadas (evita el N+1 de
 * `PublicAccommodationResource::publicDescription()`).
 */
final class AccommodationMapper
{
    public const EAGER = ['city.state', 'state', 'type', 'services', 'descriptions', 'images', 'plan.features'];

    private const SUMMARY_MAX_CHARS = 200;

    private const SUMMARY_SERVICES = 5;

    /**
     * @return array{id: int, slug: string, name: string, type: ?string, city: ?string, state: ?string, services: list<string>, allow_bookings: bool, summary: ?string, image: ?string}
     */
    public static function summary(Accommodation $accommodation, string $language): array
    {
        $description = self::description($accommodation, $language);
        $text = $description?->introduction ?: $description?->description;

        return [
            'id' => (int) $accommodation->id,
            'slug' => (string) $accommodation->slug,
            'name' => (string) $accommodation->name,
            'type' => $accommodation->type?->name,
            'city' => $accommodation->city?->name,
            'state' => $accommodation->state?->name ?? $accommodation->city?->state?->name,
            'services' => self::serviceNames($accommodation, self::SUMMARY_SERVICES),
            'allow_bookings' => $accommodation->allowsOnlineBookings(),
            'summary' => $text !== null ? Str::limit(self::plain($text), self::SUMMARY_MAX_CHARS) : null,
            'image' => $accommodation->images->first()?->url,
        ];
    }

    /**
     * Descripción en el idioma pedido; si no hay, la primera que exista.
     */
    public static function description(Accommodation $accommodation, string $language): ?AccommodationDescription
    {
        return $accommodation->descriptions->firstWhere('language_id', $language)
            ?? $accommodation->descriptions->first();
    }

    /**
     * Introducción + descripción en texto plano. En muchas fichas la
     * introducción es el comienzo de la descripción: en ese caso va una sola.
     */
    public static function fullDescription(Accommodation $accommodation, string $language): ?string
    {
        $description = self::description($accommodation, $language);
        $intro = self::plain((string) $description?->introduction);
        $body = self::plain((string) $description?->description);

        $text = match (true) {
            $intro === '' => $body,
            $body === '' => $intro,
            str_starts_with($body, rtrim($intro, '. ')), str_contains($body, $intro) => $body,
            default => $intro.' '.$body,
        };

        return $text !== '' ? $text : null;
    }

    /**
     * Nombres de servicios, los destacados primero.
     *
     * @return list<string>
     */
    public static function serviceNames(Accommodation $accommodation, ?int $limit = null): array
    {
        $names = $accommodation->services
            ->sortByDesc(fn ($service) => (int) $service->is_highlighted)
            ->pluck('name')
            ->filter()
            ->values();

        return ($limit !== null ? $names->take($limit) : $names)->all();
    }

    /**
     * Texto sin HTML ni espacios repetidos (las descripciones vienen del editor
     * del PMS).
     */
    public static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
