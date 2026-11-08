<?php

namespace App\Repositories;

use App\Models\Accommodation;
use App\Models\Extra;
use App\Models\Reservation;
use App\Models\ReservationExtra;
use App\Repositories\Concerns\ScopesToAccommodation;

/**
 * Extras del alojamiento (ABM del PMS) y las líneas que el hotel quita de una reserva.
 * Montos: la API guarda centavos y expone pesos.
 */
class ExtraRepository
{
    use ScopesToAccommodation;

    /** @return array<string, mixed> */
    public function index(int $accommodationId): array
    {
        $accommodation = $this->visibleAccommodation($accommodationId);
        $extras = Extra::where('accommodation_id', $accommodation->id)->orderBy('sort_order')->orderBy('id')->get();
        $used = $extras->pluck('template')->filter()->all();

        return [
            'currency' => $this->currency($accommodation),
            'extras' => $extras->map(fn (Extra $extra) => $this->payload($extra))->values()->all(),
            // Las que el hotel todavía no usó, para "Agregar desde sugeridos".
            'templates' => collect(Extra::TEMPLATES)
                ->reject(fn (array $t, string $key) => in_array($key, $used, true))
                ->map(fn (array $t, string $key) => ['key' => $key, ...$t])
                ->values()->all(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function store(array $data): array
    {
        $accommodation = $this->writableAccommodation((int) $data['accommodation_id']);

        Extra::create([
            'accommodation_id' => $accommodation->id,
            'template' => $data['template'] ?? null,
            'sort_order' => (int) Extra::where('accommodation_id', $accommodation->id)->max('sort_order') + 1,
            ...$this->columns($data),
        ]);

        return $this->index($accommodation->id);
    }

    /** @param  array<string, mixed>  $data */
    public function update(int $id, array $data): array
    {
        $extra = $this->writableExtra($id);
        $extra->update($this->columns($data));

        return $this->index((int) $extra->accommodation_id);
    }

    /** Baja lógica: las reservas que lo pidieron conservan su línea. */
    public function destroy(int $id): array
    {
        $extra = $this->writableExtra($id);
        $extra->delete();

        return $this->index((int) $extra->accommodation_id);
    }

    /** El hotel quita un extra de la reserva (por ejemplo, no hay late check-out ese día). */
    public function removeFromReservation(string $reservationId, int $lineId): void
    {
        $reservation = Reservation::findOrFail($reservationId);
        $this->writableAccommodation((int) $reservation->accommodation_id);

        abort_if(
            (int) $reservation->status_id === Reservation::STATUS_CHECKOUT || $reservation->cancelled_at !== null,
            422,
            'La reserva ya está cerrada.',
        );

        ReservationExtra::where('reservation_id', $reservation->id)->whereKey($lineId)->firstOrFail()->delete();
    }

    /** @return array<string, mixed> */
    public function payload(Extra $extra): array
    {
        return [
            'id' => $extra->id,
            'template' => $extra->template,
            'name' => $extra->name,
            'description' => $extra->description,
            'image_url' => $extra->image_url,
            'price' => $extra->price / 100,
            'charge_mode' => $extra->charge_mode,
            'max_quantity' => $extra->max_quantity,
            'price_includes_tax' => $extra->price_includes_tax,
            'requires_confirmation' => $extra->requires_confirmation,
            'enabled' => $extra->enabled,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'image_url' => $data['image_url'] ?? null,
            'price' => (int) round(((float) $data['price']) * 100),
            'charge_mode' => $data['charge_mode'],
            'max_quantity' => (int) $data['max_quantity'],
            'price_includes_tax' => (bool) ($data['price_includes_tax'] ?? true),
            'requires_confirmation' => (bool) ($data['requires_confirmation'] ?? true),
            'enabled' => (bool) ($data['enabled'] ?? true),
        ];
    }

    private function writableExtra(int $id): Extra
    {
        $extra = Extra::findOrFail($id);
        $this->writableAccommodation((int) $extra->accommodation_id);

        return $extra;
    }

    private function currency(Accommodation $accommodation): string
    {
        $code = strtoupper(trim((string) $accommodation->currency_id));

        return strlen($code) === 3 ? $code : 'ARS';
    }
}
