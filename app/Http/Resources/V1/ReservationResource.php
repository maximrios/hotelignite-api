<?php

namespace App\Http\Resources\V1;

use App\Repositories\ReservationRepository;
use App\Repositories\TravelerRepository;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato de lectura de una reserva para el PMS. Expone campos primitivos ya
 * normalizados; el frontend sólo los formatea (símbolo de moneda, fecha).
 *
 * Las filas legacy se normalizaron en 2026_10_27_000001 (fechas, centavos,
 * habitación); los fallbacks a `rate_total` / `arrival` quedan para lo que
 * siga entrando por los endpoints legacy. El estado se deriva de las fechas y de
 * los hechos registrados (check-in/out, no-show, `cancelled_at`) (ver Reservation::derivedStatus), no de la tabla `status`.
 */
class ReservationResource extends JsonResource
{
    public function toArray($request): array
    {
        $checkin = $this->effectiveCheckin();
        $checkout = $this->effectiveCheckout();

        return [
            'id' => $this->id,
            'accommodation_id' => $this->accommodation_id,
            'code' => $this->confirmation_number ?? $this->source_reference ?? (string) $this->id,
            'guest' => $this->guest?->full_name,
            'guest_first_name' => $this->guest?->first_name,
            'guest_last_name' => $this->guest?->last_name,
            'guest_email' => ReservationRepository::contact($this->guest?->email),
            'guest_phone' => ReservationRepository::contact($this->guest?->phone),
            'guest_document_type' => $this->guest?->document_type,
            'guest_document_number' => $this->guest?->document_number,
            'guest_document_country' => $this->guest?->document_country,
            'guest_birthdate' => $this->guest?->birthdate?->format('Y-m-d'),
            'guest_nationality' => $this->guest?->nationality ?: $this->countryOf($this->guest?->country_id),
            'room' => $this->room?->number,
            'room_id' => $this->room_id,
            // Tipo vendido; el asignado es el de la habitación.
            'room_type_id' => $this->room_type_id,
            'checkin' => $checkin?->format('Y-m-d'),
            'checkout' => $checkout?->format('Y-m-d'),
            'nights' => $checkin && $checkout ? (int) $checkin->diffInDays($checkout) : 0,
            'adults' => $this->adults,
            'children' => $this->children ?: $this->childrens,
            'status' => $this->derivedStatus(),
            'arrival_pending' => $this->arrivalPending(),
            'departure_overdue' => $this->departureOverdue(),
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            'no_show_at' => $this->no_show_at?->toIso8601String(),
            // Lo reservado antes de una salida anticipada.
            'booked_checkout' => $this->booked_checkout_date?->format('Y-m-d'),
            'actions' => $this->availableActions(),
            'rate' => $this->rate_amount !== null ? $this->rate_amount / 100 : null,
            'amount' => $this->normalizedAmount(),
            'currency' => $this->currency ?? 'ARS',
            'channel' => $this->channel?->name ?? $this->channel_code,
            'special_requests' => $this->special_requests,
            'internal_notes' => $this->internal_notes,
            'cancellation_reason' => $this->cancellation_reason,
            'vehicle_plate' => $this->vehicle_plate,
            'vehicle_description' => $this->vehicle_description,
            // Parte de viajeros: sólo en el detalle (show, check-in, edición).
            'travelers' => $this->whenLoaded('travelers', fn () => $this->travelers->map(fn ($g) => [
                ...app(TravelerRepository::class)->guestPayload($g),
                'primary' => (bool) $g->pivot->is_primary,
                'minor' => (bool) $g->pivot->is_minor,
                'age' => $g->pivot->age,
            ])->values()),
        ];
    }

    /** `country_id` legacy trae ISO-2 o '0'. */
    private function countryOf(?string $countryId): ?string
    {
        return $countryId !== null && strlen($countryId) === 2 ? strtoupper($countryId) : null;
    }

    /**
     * Monto total en unidades de moneda (no centavos). Los registros nuevos
     * guardan `total_amount` en centavos; los legacy del dump traen `rate_total`
     * decimal.
     */
    private function normalizedAmount(): ?float
    {
        if ($this->total_amount !== null) {
            return $this->total_amount / 100;
        }

        if ($this->rate_total !== null) {
            return (float) $this->rate_total;
        }

        return null;
    }
}
