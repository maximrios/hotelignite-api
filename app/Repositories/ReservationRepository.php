<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Requests\CancelReservationRequest;
use App\Http\Requests\CheckInReservationRequest;
use App\Http\Requests\CheckOutReservationRequest;
use App\Http\Requests\NoShowReservationRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Requests\UpdateTravelersRequest;
use App\Http\Resources\V1\ReservationResource;
use App\Http\Resources\V1\ReservationResourceCollection;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomAvailability;
use App\Models\RoomType;
use App\Repositories\Concerns\ScopesToAccommodation;
use App\Repositories\Contracts\ReservationInterface;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReservationRepository implements ReservationInterface
{
    use ScopesToAccommodation;

    /** SQLSTATE de exclusion_violation (EXCLUDE reservations_room_no_overlap). */
    private const EXCLUSION_VIOLATION = '23P01';

    /** Filas de `reservations_status` (catálogo legacy). */
    private const STATUS_CONFIRMED = 2;

    private const STATUS_CANCELLED = 3;

    /** Canal "Mostrador": walk-in y telefónicas, el default de un alta en el rack. */
    private const CHANNEL_FRONT_DESK = 1;

    public function all(Request $request)
    {
        $reservations = Reservation::visibleTo($this->tenant())
            ->with(['guest', 'room', 'channel'])
            ->when(
                $request->filled('accommodation_id'),
                fn ($query) => $query->where('accommodation_id', $request->input('accommodation_id'))
            )
            // Ventana del rack: toda reserva que ocupe al menos una noche de
            // [from, to). checkout es exclusivo (día de salida).
            // Un huésped con check-in y sin check-out sigue en la habitación
            // aunque su salida haya quedado antes de la ventana (salida pendiente).
            ->when(
                $request->filled('from'),
                fn ($query) => $query->where(fn ($q) => $q
                    ->whereRaw('COALESCE(checkout_date, departure) > ?', [$request->input('from')])
                    ->orWhere(fn ($q) => $q
                        ->whereNotNull('checked_in_at')
                        ->whereNull('checked_out_at')
                        ->whereNull('cancelled_at')
                        ->whereNull('no_show_at')))
            )
            ->when(
                $request->filled('to'),
                fn ($query) => $query->whereRaw('COALESCE(checkin_date, arrival) < ?', [$request->input('to')])
            )
            // El id ya no es secuencial (UUID); ordenamos por la fecha de estancia
            // efectiva, contemplando filas legacy (arrival) y nuevas (checkin_date).
            ->orderByRaw('COALESCE(checkin_date, arrival) DESC')
            // Desempate: sin un orden total, las páginas pueden repetir o saltear filas.
            ->orderBy('id')
            ->paginate($request->integer('per_page', 25));

        return new ReservationResourceCollection($reservations);
    }

    /**
     * Mueve una reserva desde el rack (habitación y/o fechas). Reglas por estado:
     *
     * - arriving: todo, incluso desasignar. La llegada no puede quedar en el pasado.
     * - in-house: la llegada es un hecho, no se mueve; sí la salida (≥ hoy) y la
     *   habitación (room move). No se puede desasignar.
     * - departing: sólo la salida. Cambiar de habitación exige extender la estadía.
     * - out / cancelled: sólo lectura.
     *
     * Si cambian las noches y la reserva tiene tarifa por noche, el total se
     * recalcula a tarifa × noches. El solapamiento lo garantiza la base (EXCLUDE);
     * el chequeo previo es para devolver un mensaje con la reserva en conflicto.
     *
     * Desde el detalle también se editan ocupación (adultos ≤ capacidad del tipo
     * de la habitación), notas y los datos del huésped; esos errores salen por
     * campo. Mismo candado: out / cancelled no se tocan.
     */
    public function update(string $id, UpdateReservationRequest $request): ReservationResource
    {
        $reservation = $this->findWritable($id);
        $status = $reservation->derivedStatus();

        abort_if(in_array($status, ['out', 'cancelled', 'no_show'], true), 422, 'Esta reserva está cerrada y no se puede modificar.');

        $data = $request->validated();
        $today = Reservation::today();

        $oldCheckin = $reservation->effectiveCheckin();
        $oldCheckout = $reservation->effectiveCheckout();
        $checkin = isset($data['checkin_date']) ? Carbon::parse($data['checkin_date']) : $oldCheckin;
        $checkout = isset($data['checkout_date']) ? Carbon::parse($data['checkout_date']) : $oldCheckout;
        $roomId = array_key_exists('room_id', $data) ? $data['room_id'] : $reservation->room_id;
        $roomId = $roomId === null ? null : (int) $roomId;

        abort_if($checkin === null || $checkout === null, 422, 'La reserva no tiene fechas.');
        abort_if($checkout->lte($checkin), 422, 'La salida tiene que ser posterior a la llegada.');

        $datesChanged = $oldCheckin === null || $oldCheckout === null
            || ! $checkin->equalTo($oldCheckin) || ! $checkout->equalTo($oldCheckout);
        $roomChanged = $roomId !== ($reservation->room_id === null ? null : (int) $reservation->room_id);

        if ($status === 'arriving') {
            abort_if($datesChanged && $checkin->lt($today), 422, 'La llegada no puede quedar en el pasado.');
        } else {
            abort_if($oldCheckin === null || ! $checkin->equalTo($oldCheckin), 422, 'El huésped ya llegó: la fecha de llegada no se puede cambiar.');
            // Con la salida vencida (sin check-out) se pueden editar notas o datos.
            abort_if($datesChanged && $checkout->lt($today), 422, 'La salida no puede quedar en el pasado.');
            abort_if($roomId === null, 422, 'Una reserva con el huésped en el hotel tiene que tener habitación.');
            abort_if(
                $status === 'departing' && $roomChanged && ! $checkout->gt($today),
                422,
                'Una reserva que sale hoy sólo puede cambiar de habitación si se extiende.'
            );
        }

        $details = array_intersect_key($data, array_flip(['adults', 'children', 'special_requests', 'internal_notes']));
        $guestData = $data['guest'] ?? [];

        if (! $datesChanged && ! $roomChanged && $details === [] && $guestData === []) {
            return new ReservationResource($reservation->load(['guest', 'room', 'channel']));
        }

        try {
            DB::transaction(function () use ($reservation, $roomId, $checkin, $checkout, $oldCheckin, $oldCheckout, $datesChanged, $roomChanged, $details, $guestData) {
                $this->applyDetails($reservation, $roomId, $details, $guestData);

                if (! $datesChanged && ! $roomChanged) {
                    $reservation->save();

                    return;
                }

                $room = null;

                if ($roomId !== null) {
                    // El lock serializa los movimientos concurrentes hacia la misma
                    // habitación; la exclusión de la base es la red de abajo.
                    $room = $this->lockRoom($reservation, $roomId);

                    $conflict = $this->conflictFor($roomId, $checkin, $checkout, $reservation->id);

                    if ($conflict !== null) {
                        abort(409, "La habitación {$room->number} está ocupada por {$this->label($conflict)} en esas fechas.");
                    }
                }

                // Tipo vendido: se fija la primera vez que se conoce, para que un
                // room move posterior quede registrado como upgrade/downgrade.
                if ($reservation->room_type_id === null) {
                    $reservation->room_type_id = $reservation->room?->room_type_id ?? $room?->room_type_id;
                }

                $nights = (int) $checkin->diffInDays($checkout);

                if ($datesChanged && $reservation->rate_amount !== null) {
                    $reservation->total_amount = $reservation->rate_amount * $nights;
                }

                $reservation->room_id = $roomId;
                $reservation->checkin_date = $checkin;
                $reservation->checkout_date = $checkout;
                $reservation->arrival = $checkin->toDateString();
                $reservation->departure = $checkout->toDateString();
                $reservation->setAttribute('nights', $nights);
                $reservation->save();

                if ($datesChanged && $reservation->room_type_id !== null) {
                    $this->adjustAvailability($reservation->room_type_id, $oldCheckin, $oldCheckout, +1);
                    $this->adjustAvailability($reservation->room_type_id, $checkin, $checkout, -1);
                }
            });
        } catch (QueryException $e) {
            abort_if($e->getCode() === self::EXCLUSION_VIOLATION, 409, 'La habitación ya está ocupada en esas fechas.');

            throw $e;
        }

        return new ReservationResource($reservation->refresh()->load(['guest', 'room', 'channel']));
    }

    /**
     * Ocupación, notas y contacto del huésped. La capacidad se mide contra el
     * tipo de la habitación donde va a quedar la reserva (puede estar cambiando
     * en el mismo PATCH).
     */
    /**
     * Capacidad del tipo: adultos y niños máximos por separado (si se cargaron)
     * y ocupación máxima total. Un tipo sin datos no limita.
     *
     * @return array<string, string>
     */
    private function capacityErrors(?RoomType $type, int $adults, int $children): array
    {
        if ($type === null) {
            return [];
        }

        $maxAdults = (int) ($type->max_adults ?? 0);
        $maxChildren = $type->max_children;
        $maxTotal = (int) ($type->max_occupancy ?? 0);

        return match (true) {
            $maxAdults > 0 && $adults > $maxAdults => ['adults' => "La habitación admite hasta {$maxAdults} adultos."],
            $maxChildren !== null && $children > (int) $maxChildren => ['children' => "La habitación admite hasta {$maxChildren} niños."],
            $maxTotal > 0 && $adults + $children > $maxTotal => ['adults' => "La habitación admite hasta {$maxTotal} personas."],
            default => [],
        };
    }

    private function applyDetails(Reservation $reservation, ?int $roomId, array $details, array $guestData): void
    {
        if ((isset($details['adults']) || isset($details['children'])) && $roomId !== null) {
            $this->throwIf($this->capacityErrors(
                Room::with('roomType')->find($roomId)?->roomType,
                (int) ($details['adults'] ?? $reservation->adults ?? 1),
                (int) ($details['children'] ?? $reservation->children ?? 0),
            ));
        }

        if (array_key_exists('children', $details)) {
            $details['children'] ??= 0;
            // El resource cae a la columna legacy cuando `children` es 0.
            $details['childrens'] = $details['children'];
        }
        $reservation->fill($details);

        if ($guestData !== [] && $reservation->guest !== null) {
            $guest = $reservation->guest;
            foreach (['first_name', 'last_name', 'phone'] as $field) {
                if (array_key_exists($field, $guestData)) {
                    // Columnas legacy NOT NULL: '' en vez de NULL.
                    $guest->{$field} = trim((string) $guestData[$field]);
                }
            }
            if (array_key_exists('email', $guestData)) {
                $guest->email = $guestData['email'] ?? '';
            }
            // Columnas nuevas, nullable.
            foreach (['document_type', 'document_number', 'nationality'] as $field) {
                if (array_key_exists($field, $guestData)) {
                    $guest->{$field} = $guestData[$field];
                }
            }
            $guest->save();
        }
    }

    /**
     * Alta desde el rack (walk-in / telefónica). Reglas acordadas con hotelería:
     * siempre con habitación física; llegada desde ayer (alta retroactiva del
     * walk-in de anoche); adultos dentro de la capacidad del tipo; tarifa 0 sólo
     * como cortesía explícita; nace confirmada. Los errores salen por campo.
     */
    public function store(StoreReservationRequest $request): ReservationResource
    {
        $accommodation = $this->writableAccommodation($request->integer('accommodation_id'));
        $data = $request->validated();

        $checkin = Carbon::parse($data['checkin_date']);
        $checkout = Carbon::parse($data['checkout_date']);
        $nights = (int) $checkin->diffInDays($checkout);

        $errors = [];

        if ($checkin->lt(Reservation::today()->subDay())) {
            $errors['checkin_date'] = 'La llegada puede ser como mucho de ayer.';
        }
        if ($nights > 365) {
            $errors['checkout_date'] = 'La estadía no puede superar un año.';
        }
        if ((float) $data['rate'] === 0.0 && ! ($data['complimentary'] ?? false)) {
            $errors['rate'] = 'Una tarifa en 0 tiene que marcarse como cortesía.';
        }

        $rate = (int) round(((float) $data['rate']) * 100);
        $total = $rate * $nights;
        $nightly = null;

        // Con plan: si la tarifa es la cotizada, se guarda el precio de cada noche
        // (snapshot: cambios posteriores en la grilla no tocan lo vendido). Si el
        // usuario la cambió, queda marcada como tarifa manual.
        if (! empty($data['rate_plan_id'])) {
            $room = Room::visibleTo($this->tenant())->with('roomType')->find($data['room_id']);
            $plan = $room?->roomType ? app(PricingRepository::class)->plansFor($room->roomType)->firstWhere('id', (int) $data['rate_plan_id']) : null;

            if ($plan === null) {
                $errors['rate_plan_id'] = 'El plan de tarifa no se vende en este tipo de habitación.';
            } else {
                $quote = app(PricingRepository::class)->quoteStay(
                    $room->roomType, $plan, $checkin, $checkout, (int) $data['adults'], (int) ($data['children'] ?? 0)
                );
                if ($quote['total'] !== null && (int) round($quote['total'] / $nights) === $rate) {
                    $total = $quote['total'];
                    $nightly = $quote['nightly'];
                }
            }
        }

        if (($data['guarantee_type'] ?? null) === 'deposit' && isset($data['deposit_amount'])
            && (int) round($data['deposit_amount'] * 100) > $total) {
            $errors['deposit_amount'] = 'El depósito no puede superar el total.';
        }

        $guest = null;
        if (! empty($data['guest_id'])) {
            $guest = $this->visibleGuests()->whereKey($data['guest_id'])->first();
            if ($guest === null) {
                $errors['guest_id'] = 'El huésped no existe.';
            }
        }

        $this->throwIf($errors);

        try {
            $reservation = DB::transaction(function () use ($data, $accommodation, $checkin, $checkout, $nights, $rate, $total, $nightly, $guest) {
                $room = Room::whereKey($data['room_id'])->lockForUpdate()->with('roomType')->first();

                if ($room === null || (int) $room->accommodation_id !== (int) $accommodation->id) {
                    $this->throwIf(['room_id' => 'La habitación no pertenece a este alojamiento.']);
                }
                if ($room->archived_at !== null) {
                    $this->throwIf(['room_id' => "La habitación {$room->number} está dada de baja."]);
                }
                if ($room->housekeeping_status === 'ooo' || in_array($room->status, ['maintenance', 'blocked'], true)) {
                    $this->throwIf(['room_id' => "La habitación {$room->number} está fuera de servicio."]);
                }

                $this->throwIf($this->capacityErrors($room->roomType, (int) $data['adults'], (int) ($data['children'] ?? 0)));

                $conflict = $this->conflictFor($room->id, $checkin, $checkout);
                if ($conflict !== null) {
                    $this->throwIf(['room_id' => "La habitación {$room->number} está ocupada por {$this->label($conflict)} en esas fechas."]);
                }

                $guest ??= Guest::create([
                    'first_name' => trim($data['guest']['first_name']),
                    'last_name' => trim($data['guest']['last_name']),
                    // Columnas legacy NOT NULL: '' en vez de NULL.
                    'email' => $data['guest']['email'] ?? '',
                    'phone' => trim($data['guest']['phone'] ?? ''),
                ]);

                $reservation = new Reservation([
                    'accommodation_id' => $accommodation->id,
                    'guest_id' => $guest->id,
                    'room_id' => $room->id,
                    'room_type_id' => $room->room_type_id,
                    'rate_plan_id' => $data['rate_plan_id'] ?? null,
                    'channel_id' => $data['channel_id'] ?? self::CHANNEL_FRONT_DESK,
                    'status_id' => self::STATUS_CONFIRMED,
                    'confirmation_number' => $this->newConfirmationNumber(),
                    'checkin_date' => $checkin,
                    'checkout_date' => $checkout,
                    'adults' => $data['adults'],
                    'children' => $data['children'] ?? 0,
                    'rate_amount' => $rate,
                    'total_amount' => $total,
                    'nightly_rates' => $nightly,
                    'rate_is_manual' => $nightly === null,
                    'currency' => $this->currencyOf($accommodation->currency_id),
                    'guarantee_type' => $data['guarantee_type'] ?? 'none',
                    'deposit_amount' => isset($data['deposit_amount']) ? (int) round($data['deposit_amount'] * 100) : null,
                    'special_requests' => $data['special_requests'] ?? null,
                    'internal_notes' => $data['internal_notes'] ?? null,
                ]);

                // Columnas legacy NOT NULL que el modelo no expone como fillable.
                $reservation->forceFill([
                    'arrival' => $checkin->toDateString(),
                    'departure' => $checkout->toDateString(),
                    'nights' => $nights,
                    'currency_id' => $this->currencyOf($accommodation->currency_id),
                    'language_id' => 'es',
                    'package_id' => 0,
                    'payment_method_id' => 0,
                    'reservation_type' => 1,
                    'created' => now(),
                    'modified' => now(),
                    'user_created' => $this->tenant()->id,
                ]);
                $reservation->save();
                $reservation->travelers()->attach($guest->id, ['is_primary' => true]);

                $this->adjustAvailability($room->room_type_id, $checkin, $checkout, -1);

                return $reservation;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === self::EXCLUSION_VIOLATION) {
                $this->throwIf(['room_id' => 'La habitación ya está ocupada en esas fechas.']);
            }

            throw $e;
        }

        return new ReservationResource($reservation->refresh()->load(['guest', 'room', 'channel']));
    }

    /**
     * Precio sugerido para el form de alta: el primer plan activo del tipo de la
     * habitación con tarifa cargada para TODAS las noches. Si ninguno cubre la
     * estadía, `rate` es null y el hotelero la carga a mano (el caso habitual).
     */
    /**
     * Cotización para el alta: precio por noche del plan elegido (o el base) con
     * el ajuste por ocupación, y el total de cada plan vendible en el tipo para
     * elegir. Si faltan noches sin tarifa, no hay total: el PMS avisa y pide
     * cargarla a mano.
     */
    public function quote(Request $request): array
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'checkin_date' => ['required', 'date_format:Y-m-d'],
            'checkout_date' => ['required', 'date_format:Y-m-d', 'after:checkin_date'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'rate_plan_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $room = Room::visibleTo($this->tenant())->with('roomType')->find($data['room_id']);
        abort_if($room === null, 404);

        $checkin = Carbon::parse($data['checkin_date']);
        $checkout = Carbon::parse($data['checkout_date']);
        $nights = (int) $checkin->diffInDays($checkout);
        $type = $room->roomType;
        $adults = (int) ($data['adults'] ?? $type?->standard_occupancy ?? 2);
        $children = (int) ($data['children'] ?? 0);

        $empty = [
            'room_id' => $room->id, 'max_occupancy' => $type?->max_occupancy, 'nights' => $nights,
            'rate_plan' => null, 'rate' => null, 'total' => null, 'nightly' => [],
            'missing_dates' => [], 'closed_dates' => [], 'min_stay' => null, 'plans' => [],
        ];
        if ($type === null) {
            return $empty;
        }

        $pricing = app(PricingRepository::class);
        $plans = $pricing->plansFor($type);
        $quotes = $plans->map(fn (RatePlan $plan) => $pricing->quoteStay($type, $plan, $checkin, $checkout, $adults, $children));

        // El pedido; si no, el base; si no hay base, el primero que cubra todo.
        $chosen = $quotes->first(fn (array $q) => $q['plan']->id === (int) ($data['rate_plan_id'] ?? 0))
            ?? $quotes->first(fn (array $q) => $q['plan']->is_base)
            ?? $quotes->first(fn (array $q) => $q['total'] !== null)
            ?? $quotes->first();

        if ($chosen === null) {
            return [...$empty, 'missing_dates' => $pricing->quoteStay($type, null, $checkin, $checkout, $adults, $children)['missing']];
        }

        return [
            ...$empty,
            'rate_plan' => ['id' => $chosen['plan']->id, 'name' => $chosen['plan']->name],
            // Unidades de moneda. Con precios distintos por noche, el promedio.
            'rate' => $chosen['total'] !== null ? round($chosen['total'] / $nights / 100, 2) : null,
            'total' => $chosen['total'] !== null ? $chosen['total'] / 100 : null,
            'nightly' => array_map(fn (array $n) => ['date' => $n['date'], 'price' => $n['price'] / 100], $chosen['nightly']),
            'missing_dates' => $chosen['missing'],
            'closed_dates' => $chosen['closed'],
            'min_stay' => $chosen['min_stay'],
            'plans' => $quotes->map(fn (array $q) => [
                'id' => $q['plan']->id,
                'name' => $q['plan']->name,
                'code' => $q['plan']->code,
                'cancellation' => $q['plan']->cancellation,
                'meal_plan' => $q['plan']->meal_plan,
                'total' => $q['total'] !== null ? $q['total'] / 100 : null,
            ])->values()->all(),
        ];
    }

    /**
     * Huéspedes que ya se alojaron en alguno de los alojamientos del usuario,
     * por email o teléfono exactos (nunca por nombre: homónimos). `guests` no
     * tiene tenant propio, así que la visibilidad sale de sus reservas: sin eso
     * cualquier hotelero podría sondear los datos de huéspedes de otro.
     */
    public function lookupGuests(Request $request): array
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'document_type' => ['nullable', 'in:dni,passport,cuit,other'],
            'document_number' => ['nullable', 'string', 'max:30'],
        ]);

        // Por documento exacto (acompañantes en el check-in).
        if (! empty($data['document_type']) && strlen(trim((string) ($data['document_number'] ?? ''))) >= 5) {
            return ['data' => app(TravelerRepository::class)->lookupByDocument($data['document_type'], $data['document_number'])];
        }

        $email = isset($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        $phone = isset($data['phone']) ? preg_replace('/\D+/', '', $data['phone']) : null;

        if (! $email && (! $phone || strlen($phone) < 6)) {
            return ['data' => []];
        }

        $guests = $this->visibleGuests()
            ->where(function ($q) use ($email, $phone) {
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if ($phone && strlen($phone) >= 6) {
                    $q->orWhereRaw("regexp_replace(phone, '\\D', '', 'g') = ?", [$phone]);
                }
            })
            ->withCount(['reservations as stays' => fn ($q) => $q->whereNull('cancelled_at')])
            ->limit(5)
            ->get();

        return ['data' => $guests->map(fn (Guest $g) => [
            'id' => $g->id,
            'name' => $g->full_name,
            'email' => self::contact($g->email),
            'phone' => self::contact($g->phone),
            'stays' => $g->stays,
        ])->values()];
    }

    /** Las columnas legacy guardan '0' o '' cuando no hay dato. */
    public static function contact(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '0' ? null : $value;
    }

    /** Titulares y acompañantes de estadías en alojamientos visibles. */
    private function visibleGuests()
    {
        return app(TravelerRepository::class)->visibleGuests();
    }

    private function conflictFor(int $roomId, Carbon $checkin, Carbon $checkout, ?string $exceptId = null): ?Reservation
    {
        return Reservation::where('room_id', $roomId)
            ->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))
            ->whereNull('cancelled_at')
            ->whereNull('no_show_at')
            ->whereNull('deleted_at')
            ->where('checkin_date', '<', $checkout->toDateString())
            ->where('checkout_date', '>', $checkin->toDateString())
            ->with('guest')
            ->first();
    }

    /** @param  array<string, string>  $errors */
    private function throwIf(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** `HI-` + 6 caracteres sin ambiguos (0/O, 1/I). Único por índice en la base. */
    private function newConfirmationNumber(): string
    {
        do {
            $code = 'HI-'.collect(range(1, 6))
                ->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])
                ->implode('');
        } while (Reservation::where('confirmation_number', $code)->exists());

        return $code;
    }

    /** Moneda ISO del alojamiento; hay filas con '0' o vacío del dump. */
    private function currencyOf(?string $currencyId): string
    {
        $code = strtoupper(trim((string) $currencyId));

        return strlen($code) === 3 ? $code : 'ARS';
    }

    public function cancel(string $id, CancelReservationRequest $request): ReservationResource
    {
        $reservation = $this->findWritable($id);

        abort_if(
            $reservation->derivedStatus() !== 'arriving',
            422,
            'Sólo se puede cancelar una reserva que todavía no llegó.'
        );

        DB::transaction(function () use ($reservation, $request) {
            $reservation->cancelled_at = now();
            $reservation->cancellation_reason = $request->validated('reason');
            $reservation->status_id = self::STATUS_CANCELLED;
            $reservation->save();

            if ($reservation->room_type_id !== null) {
                $this->adjustAvailability(
                    $reservation->room_type_id,
                    $reservation->effectiveCheckin(),
                    $reservation->effectiveCheckout(),
                    +1
                );
            }
        });

        return new ReservationResource($reservation->load(['guest', 'room', 'channel']));
    }

    // ─── Recepción: check-in, check-out, no-show ───────────────────────────────
    // Reglas acordadas con hotelería. Cada una tiene su "deshacer" para el error
    // de mostrador; `Reservation::availableActions()` las anticipa para el PMS.

    /**
     * Check-in: desde el día de llegada (la llegada anticipada se hace
     * adelantando la llegada desde Editar), siempre con habitación, con los pax
     * reales y los datos mínimos del titular. Una habitación sucia se entrega
     * igual (avisa el PMS); fuera de servicio o con otro huésped sin salida, no.
     */
    public function checkIn(string $id, CheckInReservationRequest $request): ReservationResource
    {
        $reservation = $this->findWritable($id);
        $today = Reservation::today();
        $checkin = $reservation->effectiveCheckin();
        $checkout = $reservation->effectiveCheckout();

        abort_if($reservation->derivedStatus() !== 'arriving', 422, 'Esta reserva ya tiene check-in o está cerrada.');
        abort_if($checkin === null || $checkout === null, 422, 'La reserva no tiene fechas.');
        abort_if(
            $checkin->gt($today),
            422,
            "La llegada es el {$checkin->format('d/m')}. Para recibirlo antes, adelantá la llegada desde Editar."
        );
        abort_if($checkout->lte($today), 422, 'La estadía ya terminó: corregí las fechas antes de hacer el check-in.');

        $data = $request->validated();
        $roomId = array_key_exists('room_id', $data) && $data['room_id'] !== null
            ? (int) $data['room_id']
            : ($reservation->room_id === null ? null : (int) $reservation->room_id);

        if ($roomId === null) {
            $this->throwIf(['room_id' => 'Asigná una habitación para hacer el check-in.']);
        }

        try {
            DB::transaction(function () use ($reservation, $roomId, $checkin, $checkout, $data) {
                $room = $this->lockRoom($reservation, $roomId);

                abort_if(
                    $room->housekeeping_status === 'oos',
                    409,
                    "La habitación {$room->number} está fuera de inventario."
                );

                if ($roomId !== (int) $reservation->room_id) {
                    $conflict = $this->conflictFor($roomId, $checkin, $checkout, $reservation->id);
                    if ($conflict !== null) {
                        abort(409, "La habitación {$room->number} está ocupada por {$this->label($conflict)} en esas fechas.");
                    }
                }

                // Un huésped que no registró la salida todavía ocupa la habitación,
                // aunque sus fechas ya no se pisen con éstas.
                $occupant = Reservation::where('room_id', $roomId)
                    ->where('id', '<>', $reservation->id)
                    ->whereNotNull('checked_in_at')
                    ->whereNull('checked_out_at')
                    ->whereNull('cancelled_at')
                    ->whereNull('deleted_at')
                    ->with('guest')
                    ->first();
                abort_if(
                    $occupant !== null,
                    409,
                    "La habitación {$room->number} todavía tiene a {$this->label($occupant ?? $reservation)} sin check-out."
                );

                $details = array_intersect_key($data, array_flip(['adults', 'children']));
                $this->applyDetails($reservation, $roomId, $details, $data['guest'] ?? []);
                app(TravelerRepository::class)->sync(
                    $reservation,
                    $data['guest'] ?? [],
                    array_key_exists('companions', $data) ? $data['companions'] : null,
                    array_key_exists('vehicle', $data) ? ($data['vehicle'] ?? []) : null,
                );

                if ($reservation->room_type_id === null) {
                    $reservation->room_type_id = $room->room_type_id;
                }

                $reservation->room_id = $roomId;
                $reservation->checked_in_at = now();
                $reservation->checkin = now();
                $reservation->status_id = Reservation::STATUS_CHECKIN;
                $reservation->pre_checkin_housekeeping = $room->housekeeping_status;
                $reservation->save();

                // Vacía-limpia → ocupada-limpia; vacía-sucia → ocupada-sucia.
                $room->housekeeping_status = $room->housekeeping_status === 'vd' ? 'od' : 'oc';
                $room->save();
            });
        } catch (QueryException $e) {
            abort_if($e->getCode() === self::EXCLUSION_VIOLATION, 409, 'La habitación ya está ocupada en esas fechas.');

            throw $e;
        }

        return $this->fresh($reservation);
    }

    /** Deshacer un check-in hecho por error: dentro de las 24 hs y sin check-out. */
    public function undoCheckIn(string $id): ReservationResource
    {
        $reservation = $this->findWritable($id);

        abort_unless(
            in_array('undo_check_in', $reservation->availableActions(), true),
            422,
            'Este check-in ya no se puede deshacer.'
        );

        DB::transaction(function () use ($reservation) {
            $room = $reservation->room_id !== null ? Room::whereKey($reservation->room_id)->lockForUpdate()->first() : null;

            if ($room !== null && in_array($room->housekeeping_status, ['oc', 'od'], true)) {
                $room->housekeeping_status = $reservation->pre_checkin_housekeeping
                    ?? ($room->housekeeping_status === 'od' ? 'vd' : 'vc');
                $room->save();
            }

            $reservation->checked_in_at = null;
            $reservation->checkin = null;
            $reservation->pre_checkin_housekeeping = null;
            $reservation->status_id = self::STATUS_CONFIRMED;
            $reservation->save();
        });

        return $this->fresh($reservation);
    }

    /**
     * Check-out. Antes de la fecha de salida es anticipado: la salida pasa a hoy
     * (libera las noches en el calendario) y el usuario decide si se cobran las
     * noches restantes (`charge_remaining`: se mantiene el total) o no (se
     * recalcula). Lo reservado queda guardado para deshacerlo. La habitación
     * pasa a vacía-sucia.
     */
    public function checkOut(string $id, CheckOutReservationRequest $request): ReservationResource
    {
        $reservation = $this->findWritable($id);

        abort_unless(
            in_array($reservation->derivedStatus(), ['in', 'departing'], true),
            422,
            'Sólo se puede hacer el check-out de un huésped con check-in.'
        );

        $checkin = $reservation->effectiveCheckin();
        $checkout = $reservation->effectiveCheckout();
        $today = Reservation::today();
        // Una estadía no baja de una noche, aunque se vaya el mismo día que llegó.
        $newCheckout = $today->max($checkin->copy()->addDay());
        $early = $newCheckout->lt($checkout);
        $chargeRemaining = $request->validated('charge_remaining');

        if ($early && $chargeRemaining === null) {
            $this->throwIf(['charge_remaining' => 'Indicá si se cobran las noches que no usó.']);
        }

        DB::transaction(function () use ($reservation, $checkin, $checkout, $newCheckout, $early, $chargeRemaining) {
            if ($early) {
                $nights = (int) $checkin->diffInDays($newCheckout);

                $reservation->booked_checkout_date = $checkout;
                $reservation->booked_total_amount = $reservation->total_amount;
                $reservation->checkout_date = $newCheckout;
                $reservation->departure = $newCheckout->toDateString();
                $reservation->setAttribute('nights', $nights);

                if (! $chargeRemaining && $reservation->rate_amount !== null) {
                    $reservation->total_amount = $reservation->rate_amount * $nights;
                }

                if ($reservation->room_type_id !== null) {
                    $this->adjustAvailability($reservation->room_type_id, $newCheckout, $checkout, +1);
                }
            }

            $reservation->checked_out_at = now();
            $reservation->checkout = now();
            $reservation->status_id = Reservation::STATUS_CHECKOUT;
            $reservation->save();

            // Con save() (no update masivo) para que corran los eventos del modelo
            // (historial de housekeeping).
            $room = $reservation->room_id !== null ? Room::find($reservation->room_id) : null;
            if ($room !== null) {
                $room->housekeeping_status = 'vd';
                $room->save();
            }
        });

        return $this->fresh($reservation);
    }

    /** Deshacer el check-out del día: reabre la reserva y, si fue anticipado, la estadía reservada. */
    public function undoCheckOut(string $id): ReservationResource
    {
        $reservation = $this->findWritable($id);

        abort_unless(
            in_array('undo_check_out', $reservation->availableActions(), true),
            422,
            'Este check-out ya no se puede deshacer.'
        );

        try {
            DB::transaction(function () use ($reservation) {
                $room = $reservation->room_id !== null ? Room::whereKey($reservation->room_id)->lockForUpdate()->first() : null;
                $booked = $reservation->booked_checkout_date;

                if ($booked !== null) {
                    $from = $reservation->effectiveCheckout();
                    $conflict = $room !== null ? $this->conflictFor($room->id, $from, $booked, $reservation->id) : null;
                    if ($conflict !== null) {
                        abort(409, "Las noches liberadas ya se vendieron a {$this->label($conflict)}: no se puede reabrir la estadía.");
                    }

                    $reservation->checkout_date = $booked;
                    $reservation->departure = $booked->toDateString();
                    $reservation->setAttribute('nights', (int) $reservation->effectiveCheckin()->diffInDays($booked));
                    $reservation->total_amount = $reservation->booked_total_amount ?? $reservation->total_amount;
                    $reservation->booked_checkout_date = null;
                    $reservation->booked_total_amount = null;

                    if ($reservation->room_type_id !== null) {
                        $this->adjustAvailability($reservation->room_type_id, $from, $booked, -1);
                    }
                }

                $reservation->checked_out_at = null;
                $reservation->checkout = null;
                $reservation->status_id = Reservation::STATUS_CHECKIN;
                $reservation->save();

                if ($room !== null && in_array($room->housekeeping_status, ['vc', 'vd'], true)) {
                    $room->housekeeping_status = $room->housekeeping_status === 'vd' ? 'od' : 'oc';
                    $room->save();
                }
            });
        } catch (QueryException $e) {
            abort_if($e->getCode() === self::EXCLUSION_VIOLATION, 409, 'Las noches liberadas ya se vendieron: no se puede reabrir la estadía.');

            throw $e;
        }

        return $this->fresh($reservation);
    }

    /**
     * No-show: sin check-in, desde las 20 hs del día de llegada. Es un estado
     * propio (no una cancelación) y libera la habitación y el cupo de toda la
     * estadía. El cargo queda para después; la nota va en el motivo.
     */
    public function markNoShow(string $id, NoShowReservationRequest $request): ReservationResource
    {
        $reservation = $this->findWritable($id);

        if (! in_array('no_show', $reservation->availableActions(), true)) {
            abort_if($reservation->derivedStatus() !== 'arriving', 422, 'Sólo se puede marcar no-show una reserva sin check-in.');
            abort(422, 'El no-show se puede marcar desde las '.Reservation::NO_SHOW_HOUR.' hs del día de llegada.');
        }

        DB::transaction(function () use ($reservation, $request) {
            $reservation->no_show_at = now();
            $reservation->cancellation_reason = $request->validated('note');
            $reservation->status_id = Reservation::STATUS_NO_SHOW;
            $reservation->save();

            if ($reservation->room_type_id !== null) {
                $this->adjustAvailability(
                    $reservation->room_type_id,
                    $reservation->effectiveCheckin(),
                    $reservation->effectiveCheckout(),
                    +1
                );
            }
        });

        return $this->fresh($reservation);
    }

    /** El huésped apareció el mismo día: vuelve a "por llegar". */
    public function undoNoShow(string $id): ReservationResource
    {
        $reservation = $this->findWritable($id);

        abort_unless(
            in_array('undo_no_show', $reservation->availableActions(), true),
            422,
            'Este no-show ya no se puede revertir.'
        );

        try {
            DB::transaction(function () use ($reservation) {
                $checkin = $reservation->effectiveCheckin();
                $checkout = $reservation->effectiveCheckout();

                // Si la habitación ya se volvió a vender, vuelve "sin asignar": el
                // huésped llegó y se le busca otra.
                if ($reservation->room_id !== null
                    && $this->conflictFor((int) $reservation->room_id, $checkin, $checkout, $reservation->id) !== null) {
                    $reservation->room_id = null;
                }

                $reservation->no_show_at = null;
                $reservation->cancellation_reason = null;
                $reservation->status_id = self::STATUS_CONFIRMED;
                $reservation->save();

                if ($reservation->room_type_id !== null) {
                    $this->adjustAvailability($reservation->room_type_id, $checkin, $checkout, -1);
                }
            });
        } catch (QueryException $e) {
            abort_if($e->getCode() === self::EXCLUSION_VIOLATION, 409, 'La habitación ya se volvió a vender en esas fechas.');

            throw $e;
        }

        return $this->fresh($reservation);
    }

    /** Habitación destino bloqueada para la transacción; del alojamiento y en servicio. */
    private function lockRoom(Reservation $reservation, int $roomId): Room
    {
        $room = Room::whereKey($roomId)->lockForUpdate()->first();

        abort_if(
            $room === null || (int) $room->accommodation_id !== (int) $reservation->accommodation_id,
            422,
            'La habitación no pertenece a este alojamiento.'
        );
        abort_if($room->archived_at !== null, 409, "La habitación {$room->number} está dada de baja.");
        abort_if(
            $room->housekeeping_status === 'ooo' || in_array($room->status, ['maintenance', 'blocked'], true),
            409,
            "La habitación {$room->number} está fuera de servicio."
        );

        return $room;
    }

    private function fresh(Reservation $reservation): ReservationResource
    {
        return new ReservationResource($reservation->refresh()->load(['guest', 'room', 'channel', 'travelers']));
    }

    /** Detalle de una reserva con el parte de viajeros (slide-out, ficha de registro). */
    public function show(string $id): ReservationResource
    {
        $reservation = Reservation::visibleTo($this->tenant())->find($id);
        abort_if($reservation === null, 404);

        return $this->fresh($reservation);
    }

    /**
     * Parte de viajeros de una reserva: datos del titular, acompañantes y
     * vehículo. Se puede completar antes del check-in o corregir después; no en
     * una reserva cancelada o no-show.
     */
    public function updateTravelers(string $id, UpdateTravelersRequest $request): ReservationResource
    {
        $reservation = $this->findWritable($id);
        abort_if(
            in_array($reservation->derivedStatus(), ['cancelled', 'no_show'], true),
            422,
            'La reserva no está vigente.'
        );

        $data = $request->validated();

        DB::transaction(function () use ($reservation, $data) {
            app(TravelerRepository::class)->sync(
                $reservation,
                $data['guest'] ?? [],
                array_key_exists('companions', $data) ? $data['companions'] : null,
                array_key_exists('vehicle', $data) ? ($data['vehicle'] ?? []) : null,
            );
        });

        return $this->fresh($reservation);
    }

    private function findWritable(string $id): Reservation
    {
        $reservation = Reservation::visibleTo($this->tenant())->with('room')->find($id);

        abort_if($reservation === null, 404);
        Gate::authorize('update', $reservation);

        return $reservation;
    }

    /**
     * Libera (+1) o toma (-1) una unidad del tipo vendido en las noches
     * [from, to). Sólo toca días ya cargados en `room_availability`: el cupo de
     * un día sin fila lo define quien publica inventario, no un movimiento.
     */
    private function adjustAvailability(int $roomTypeId, ?Carbon $from, ?Carbon $to, int $delta): void
    {
        if ($from === null || $to === null) {
            return;
        }

        RoomAvailability::where('room_type_id', $roomTypeId)
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<', $to->toDateString())
            ->update([
                'available' => DB::raw('LEAST(total, GREATEST(0, available + ('.(int) $delta.')))'),
                'updated_at' => now(),
            ]);
    }

    private function label(Reservation $reservation): string
    {
        $code = $reservation->confirmation_number ?? $reservation->source_reference ?? $reservation->id;
        $guest = $reservation->guest?->full_name;

        return $guest ? "{$guest} ({$code})" : "la reserva {$code}";
    }
}
