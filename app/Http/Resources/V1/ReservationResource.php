<?php

namespace App\Http\Resources\V1;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato de lectura de una reserva para el PMS. Expone campos primitivos ya
 * normalizados; el frontend sólo los formatea (símbolo de moneda, fecha).
 *
 * Soporta filas legacy del dump (usan `arrival`/`departure` + `rate_total`
 * decimal) y filas nuevas del PMS (usan `checkin_date`/`checkout_date` +
 * `total_amount` en centavos). El estado se deriva de las fechas + `cancelled_at`,
 * no de la tabla `status`.
 */
class ReservationResource extends JsonResource
{
    public function toArray($request): array
    {
        $checkin = $this->effectiveCheckin();
        $checkout = $this->effectiveCheckout();

        return [
            'id' => $this->id,
            'code' => $this->confirmation_number ?? $this->source_reference ?? (string) $this->id,
            'guest' => $this->guest?->full_name,
            'room' => $this->room?->number,
            'checkin' => $checkin?->format('Y-m-d'),
            'checkout' => $checkout?->format('Y-m-d'),
            'nights' => $checkin && $checkout ? $checkin->diffInDays($checkout) : 0,
            'status' => $this->derivedStatus($checkin, $checkout),
            'amount' => $this->normalizedAmount(),
            'currency' => $this->currency ?? 'ARS',
            'channel' => $this->channel?->name ?? $this->channel_code,
        ];
    }

    private function effectiveCheckin(): ?Carbon
    {
        return $this->checkin_date ?? ($this->arrival ? Carbon::parse($this->arrival) : null);
    }

    private function effectiveCheckout(): ?Carbon
    {
        return $this->checkout_date ?? ($this->departure ? Carbon::parse($this->departure) : null);
    }

    /**
     * Estado derivado. Prioridad: cancelada > finalizada > por salir > por llegar
     * > in-house. `departing` gana sobre `arriving` en un turnover del mismo día.
     */
    private function derivedStatus(?Carbon $checkin, ?Carbon $checkout): string
    {
        if ($this->cancelled_at !== null) {
            return 'cancelled';
        }

        if ($checkin === null || $checkout === null) {
            return 'arriving';
        }

        $today = Carbon::today();

        return match (true) {
            $checkout->lt($today) => 'out',
            $checkout->isSameDay($today) => 'departing',
            $checkin->gte($today) => 'arriving',
            default => 'in',
        };
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
