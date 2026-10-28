<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Guest;
use App\Models\Reservation;
use App\Repositories\Concerns\ScopesToAccommodation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parte de viajeros: todos los pasajeros de cada estadía (titular y
 * acompañantes), el registro de pasajeros del alojamiento y el directorio de
 * huéspedes. Reglas acordadas con hotelería:
 *
 * - Una fila por pasajero. Menor = menos de 18 a la llegada, por fecha de
 *   nacimiento o edad; si no hay ninguna, vale la marca manual.
 * - Se reusa el huésped que ya vino: por id (el PMS lo eligió del lookup) o por
 *   documento exacto, siempre dentro de los huéspedes visibles para el usuario.
 * - El vehículo es de la estadía (va en la reserva).
 * - `guests` no tiene tenant: la visibilidad sale de las reservas donde figura.
 */
class TravelerRepository
{
    use ScopesToAccommodation;

    public const ADULT_AGE = 18;

    /**
     * Reemplaza los acompañantes de la reserva y actualiza los datos del titular
     * y el vehículo. Se llama dentro de la transacción de quien la use.
     *
     * @param  array<string, mixed>  $primary  datos del titular (guest.*)
     * @param  list<array<string, mixed>>|null  $companions  null = no tocar
     * @param  array{plate?: ?string, description?: ?string}|null  $vehicle  null = no tocar
     */
    public function sync(Reservation $reservation, array $primary, ?array $companions, ?array $vehicle): void
    {
        $checkin = $reservation->effectiveCheckin() ?? Reservation::today();
        $titular = $reservation->guest;

        if ($titular !== null && $primary !== []) {
            $this->fillGuest($titular, $primary);
            $titular->save();
        }

        $rows = [];
        if ($titular !== null) {
            $rows[$titular->id] = $this->pivot(true, $titular, $primary, $checkin);
        }

        if ($companions !== null) {
            $seenDocuments = [];
            foreach ($companions as $i => $data) {
                $key = strtolower(($data['document_type'] ?? '').'|'.trim((string) ($data['document_number'] ?? '')));
                if (! empty($data['document_number']) && isset($seenDocuments[$key])) {
                    throw ValidationException::withMessages(["companions.{$i}.document_number" => 'Hay dos pasajeros con el mismo documento.']);
                }
                $seenDocuments[$key] = true;

                $guest = $this->resolveGuest($data);
                if ($titular !== null && $guest->id === $titular->id) {
                    throw ValidationException::withMessages(["companions.{$i}.document_number" => 'Es el mismo documento del titular.']);
                }
                $rows[$guest->id] = $this->pivot(false, $guest, $data, $checkin);
            }
        } else {
            foreach ($reservation->travelers()->wherePivot('is_primary', false)->get() as $existing) {
                $rows[$existing->id] = [
                    'is_primary' => false,
                    'is_minor' => (bool) $existing->pivot->is_minor,
                    'age' => $existing->pivot->age,
                ];
            }
        }

        $reservation->travelers()->sync($rows);

        if ($vehicle !== null) {
            $reservation->vehicle_plate = $this->plate($vehicle['plate'] ?? null);
            $reservation->vehicle_description = trim((string) ($vehicle['description'] ?? '')) ?: null;
            $reservation->save();
        }
    }

    /**
     * Huésped de un acompañante: el elegido del lookup, uno visible con el mismo
     * documento, o uno nuevo. En los dos primeros casos se actualizan sus datos.
     */
    private function resolveGuest(array $data): Guest
    {
        $guest = null;

        if (! empty($data['guest_id'])) {
            $guest = $this->visibleGuests()->whereKey($data['guest_id'])->first();
            if ($guest === null) {
                throw ValidationException::withMessages(['companions' => 'Uno de los huéspedes elegidos no existe.']);
            }
        } elseif (! empty($data['document_number'])) {
            $guest = $this->visibleGuests()
                ->where('document_type', $data['document_type'] ?? null)
                ->whereRaw('UPPER(document_number) = ?', [strtoupper(trim($data['document_number']))])
                ->first();
        }

        $guest ??= new Guest(['email' => '', 'phone' => '']);
        $this->fillGuest($guest, $data);
        $guest->save();

        return $guest;
    }

    private function fillGuest(Guest $guest, array $data): void
    {
        foreach (['first_name', 'last_name'] as $field) {
            if (array_key_exists($field, $data)) {
                $guest->{$field} = trim((string) $data[$field]);
            }
        }
        foreach (['document_type', 'nationality', 'birthdate'] as $field) {
            if (array_key_exists($field, $data)) {
                $guest->{$field} = $data[$field] ?: null;
            }
        }
        if (array_key_exists('document_number', $data)) {
            $guest->document_number = strtoupper(trim((string) $data['document_number'])) ?: null;
        }
        if (array_key_exists('document_country', $data) || array_key_exists('document_type', $data)) {
            // El país emisor sólo tiene sentido para el pasaporte.
            $guest->document_country = ($data['document_type'] ?? $guest->document_type) === 'passport'
                ? ($data['document_country'] ?? $guest->document_country)
                : null;
        }
        if (array_key_exists('phone', $data)) {
            $guest->phone = trim((string) $data['phone']);
        }
    }

    /** @return array{is_primary: bool, is_minor: bool, age: ?int} */
    private function pivot(bool $primary, Guest $guest, array $data, Carbon $checkin): array
    {
        $age = $guest->age($checkin) ?? (isset($data['age']) && $data['age'] !== '' ? (int) $data['age'] : null);

        return [
            'is_primary' => $primary,
            'is_minor' => $age !== null ? $age < self::ADULT_AGE : (bool) ($data['is_minor'] ?? false),
            'age' => $age,
        ];
    }

    private function plate(?string $plate): ?string
    {
        $plate = strtoupper(preg_replace('/[\s-]+/', '', (string) $plate) ?? '');

        return $plate !== '' ? $plate : null;
    }

    // ─── Registro de pasajeros ──────────────────────────────────────────────────

    /**
     * Una fila por pasajero y estadía. "En casa hoy": con check-in y sin
     * check-out. Por defecto, ingresos de los últimos 30 días.
     */
    public function registry(Request $request): array
    {
        $data = $request->validate([
            'accommodation_id' => ['required', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'in_house' => ['sometimes', 'boolean'],
            'nationality' => ['sometimes', 'string', 'max:10'],
            'minors' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $accommodation = $this->visibleAccommodation((int) $data['accommodation_id']);
        $today = Reservation::today();

        $query = DB::table('reservation_guests as rg')
            ->join('reservations as r', 'r.id', '=', 'rg.reservation_id')
            ->join('guests as g', 'g.id', '=', 'rg.guest_id')
            ->leftJoin('rooms as ro', 'ro.id', '=', 'r.room_id')
            ->where('r.accommodation_id', $accommodation->id)
            ->whereNull('r.deleted_at')
            ->whereNull('r.cancelled_at')
            ->whereNull('r.no_show_at');

        if ($request->boolean('in_house')) {
            $query->whereNotNull('r.checked_in_at')->whereNull('r.checked_out_at');
        } else {
            $from = $data['from'] ?? $today->copy()->subDays(30)->toDateString();
            $to = $data['to'] ?? $today->toDateString();
            $query->whereBetween('r.checkin_date', [$from, $to]);
        }

        match ($data['nationality'] ?? null) {
            null, '' => null,
            'AR' => $query->where('g.nationality', 'AR'),
            'foreign' => $query->whereNotNull('g.nationality')->where('g.nationality', '<>', 'AR'),
            default => $query->where('g.nationality', strtoupper($data['nationality'])),
        };

        if ($request->boolean('minors')) {
            $query->where('rg.is_minor', true);
        }

        if (! empty($data['q'])) {
            $term = '%'.mb_strtolower(trim($data['q'])).'%';
            $query->where(fn ($q) => $q
                ->whereRaw("LOWER(unaccent(g.last_name || ' ' || g.first_name)) LIKE unaccent(?)", [$term])
                ->orWhereRaw('LOWER(g.document_number) LIKE ?', [$term]));
        }

        $rows = $query
            ->orderByDesc('r.checkin_date')
            ->orderBy('r.id')
            ->orderByDesc('rg.is_primary')
            ->orderBy('rg.id')
            ->limit(2000)
            ->get([
                'rg.reservation_id', 'rg.guest_id', 'rg.is_primary', 'rg.is_minor', 'rg.age',
                'r.confirmation_number', 'r.source_reference', 'r.checkin_date', 'r.checkout_date',
                'r.checked_in_at', 'r.checked_out_at', 'r.vehicle_plate',
                'g.first_name', 'g.last_name', 'g.document_type', 'g.document_number', 'g.document_country',
                'g.nationality', 'g.country_id', 'g.birthdate', 'ro.number as room',
            ]);

        return ['data' => $rows->map(fn ($row) => [
            'reservation_id' => $row->reservation_id,
            'reservation_code' => $row->confirmation_number ?? $row->source_reference ?? $row->reservation_id,
            'guest_id' => $row->guest_id,
            'primary' => (bool) $row->is_primary,
            'minor' => (bool) $row->is_minor,
            'age' => $row->age,
            'first_name' => trim((string) $row->first_name),
            'last_name' => trim((string) $row->last_name),
            'document_type' => $row->document_type,
            'document_number' => $row->document_number,
            'document_country' => $row->document_country,
            'nationality' => $row->nationality ?: (strlen((string) $row->country_id) === 2 ? strtoupper($row->country_id) : null),
            'birthdate' => $row->birthdate,
            'checkin' => $row->checkin_date,
            'checkout' => $row->checkout_date,
            'checked_in_at' => $row->checked_in_at,
            'checked_out_at' => $row->checked_out_at,
            'room' => $row->room,
            'vehicle_plate' => $row->vehicle_plate,
        ])->values()];
    }

    // ─── Huéspedes ──────────────────────────────────────────────────────────────

    /** Directorio de huéspedes del alojamiento (los que figuran en alguna estadía). */
    public function guests(Request $request): array
    {
        $data = $request->validate([
            'accommodation_id' => ['required', 'integer'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $accommodation = $this->visibleAccommodation((int) $data['accommodation_id']);
        $live = fn ($q) => $q->where('reservations.accommodation_id', $accommodation->id)->whereNull('reservations.cancelled_at');

        $guests = Guest::whereHas('stays', $live)
            ->when(! empty($data['q']), function (Builder $q) use ($data) {
                $term = '%'.mb_strtolower(trim($data['q'])).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw("LOWER(unaccent(last_name || ' ' || first_name)) LIKE unaccent(?)", [$term])
                    ->orWhereRaw('LOWER(document_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            })
            ->withCount(['stays as stays_count' => $live])
            ->withMax(['stays as last_checkin' => $live], 'checkin_date')
            ->orderByDesc('last_checkin')
            ->limit(200)
            ->get();

        return ['data' => $guests->map(fn (Guest $g) => [
            ...$this->guestPayload($g),
            'stays' => (int) $g->stays_count,
            'last_checkin' => $g->last_checkin ? substr((string) $g->last_checkin, 0, 10) : null,
        ])->values()];
    }

    /** Ficha del huésped: datos, estadías en este alojamiento y vehículos usados. */
    public function guest(int $id, Request $request): array
    {
        $data = $request->validate(['accommodation_id' => ['required', 'integer']]);
        $accommodation = $this->visibleAccommodation((int) $data['accommodation_id']);

        $guest = Guest::whereKey($id)
            ->whereHas('stays', fn ($q) => $q->where('reservations.accommodation_id', $accommodation->id))
            ->first();
        abort_if($guest === null, 404);

        $stays = $guest->stays()
            ->where('reservations.accommodation_id', $accommodation->id)
            ->with('room')
            ->orderByDesc('checkin_date')
            ->get();

        $lived = $stays->filter(fn (Reservation $r) => ! in_array($r->derivedStatus(), ['cancelled', 'no_show'], true));

        return [
            ...$this->guestPayload($guest),
            'email' => ReservationRepository::contact($guest->email),
            'phone' => ReservationRepository::contact($guest->phone),
            'nights' => $lived->sum(fn (Reservation $r) => $r->nights),
            'total_spent' => $lived->where('pivot.is_primary', true)->sum(fn (Reservation $r) => (int) ($r->total_amount ?? 0)) / 100,
            'vehicles' => $stays->pluck('vehicle_plate')->filter()->unique()->values(),
            'history' => $stays->map(fn (Reservation $r) => [
                'reservation_id' => $r->id,
                'code' => $r->confirmation_number ?? $r->source_reference ?? $r->id,
                'checkin' => $r->effectiveCheckin()?->toDateString(),
                'checkout' => $r->effectiveCheckout()?->toDateString(),
                'nights' => $r->nights,
                'room' => $r->room?->number,
                'status' => $r->derivedStatus(),
                'primary' => (bool) $r->pivot->is_primary,
                'amount' => $r->pivot->is_primary && $r->total_amount !== null ? $r->total_amount / 100 : null,
                'currency' => $r->currency ?? 'ARS',
                'vehicle_plate' => $r->vehicle_plate,
            ])->values(),
        ];
    }

    /** Lookup por documento exacto, para reusar los datos de quien ya vino. */
    public function lookupByDocument(string $type, string $number): array
    {
        $guests = $this->visibleGuests()
            ->where('document_type', $type)
            ->whereRaw('UPPER(document_number) = ?', [strtoupper(trim($number))])
            ->limit(3)
            ->get();

        return $guests->map(fn (Guest $g) => $this->guestPayload($g))->values()->all();
    }

    /** @return array<string, mixed> */
    public function guestPayload(Guest $g): array
    {
        return [
            'id' => $g->id,
            'first_name' => trim((string) $g->first_name),
            'last_name' => trim((string) $g->last_name),
            'name' => $g->full_name,
            'document_type' => $g->document_type,
            'document_number' => $g->document_number,
            'document_country' => $g->document_country,
            'nationality' => $g->nationality ?: (strlen((string) $g->country_id) === 2 ? strtoupper($g->country_id) : null),
            'birthdate' => $g->birthdate?->toDateString(),
        ];
    }

    /** Huéspedes que figuran en alguna estadía de un alojamiento visible. */
    public function visibleGuests(): Builder
    {
        return Guest::whereHas('stays', fn ($q) => $q->visibleTo($this->tenant()));
    }
}
