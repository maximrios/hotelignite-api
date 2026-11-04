<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Mail\BookingGuestMail;
use App\Mail\BookingRequestReceivedMail;
use App\Models\Accommodation;
use App\Models\BookingHold;
use App\Models\BookingHoldRoom;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Support\RoomInventory;
use App\Models\RoomType;
use App\Models\User;
use App\Repositories\Contracts\BookingEngineInterface;
use App\Support\BookingDeadline;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Motor de reservas. Una habitación se vende sólo si está libre de verdad:
 * operativa, sin reserva viva, sin hold vigente, con tarifa en todas las noches
 * y sin cierre de venta. Sin datos, no se ofrece.
 */
class BookingEngineRepository implements BookingEngineInterface
{
    /** Máximo de habitaciones por solicitud online. */
    public const MAX_ROOMS = 4;

    /** Habitaciones apartadas a la vez por visitante. */
    private const MAX_ROOMS_PER_VISITOR = 6;

    /** Fracción del hotel que puede estar apartada; `MAX_ROOMS` siempre entra. */
    private const MAX_HOTEL_SHARE = 0.5;

    /** Solicitudes sin responder por email, en cada hotel. */
    private const MAX_PENDING_PER_EMAIL = 2;

    private const CHANNEL_ENGINE = 3;

    private const EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private PricingRepository $pricing,
        private ReservationRepository $reservations,
    ) {}

    /** @return array<string, mixed> */
    public function hotel(Accommodation $accommodation): array
    {
        return [
            'slug' => $accommodation->slug,
            'name' => $accommodation->name,
            'logo' => $accommodation->logo,
            'address' => $accommodation->address,
            'email' => $accommodation->email,
            'phone' => $accommodation->phone,
            'web' => $accommodation->web ?: null,
            'mode' => $accommodation->booking_mode,
        ];
    }

    /**
     * @param  array<string, mixed>  $stay
     * @return array<string, mixed>
     */
    public function search(Accommodation $accommodation, array $stay): array
    {
        [$checkin, $checkout, $adults, $children] = $this->stay($stay);
        $requested = (int) ($stay['rooms'] ?? 1);

        $types = RoomType::where('accommodation_id', $accommodation->id)
            ->with(['descriptions', 'images'])
            ->get()
            ->filter(fn (RoomType $type) => $requested > 1
                ? (int) $type->max_occupancy > 0
                : $this->fits($type, $adults, $children));

        $rooms = $types->map(function (RoomType $type) use ($checkin, $checkout, $adults, $children, $requested) {
            $free = $this->freeRooms($type, $checkin, $checkout)->count();
            // Con varias habitaciones se cotiza un reparto parejo; el hold cierra el precio real.
            $perRoomAdults = min((int) ceil($adults / $requested), max(1, (int) $type->max_occupancy));
            $perRoomChildren = min((int) ceil($children / $requested), max(0, (int) $type->max_occupancy - $perRoomAdults));
            $plans = $free > 0 ? $this->sellablePlans($type, $checkin, $checkout, $perRoomAdults, $perRoomChildren) : collect();

            if ($plans->isEmpty()) {
                return null;
            }

            $es = $type->descriptions->firstWhere('language_id', 'es') ?? $type->descriptions->first();

            return [
                'id' => $type->id,
                'name' => $es?->name ?? 'Habitación',
                'description' => $es?->description,
                'max_occupancy' => $type->max_occupancy,
                'size' => $type->size,
                'images' => $type->images->pluck('url')->filter()->values()->all(),
                'rooms_left' => $free,
                'plans' => $plans->values()->all(),
            ];
        })->filter()->values();

        return [
            'checkin' => $checkin->toDateString(),
            'checkout' => $checkout->toDateString(),
            'nights' => (int) $checkin->diffInDays($checkout),
            'currency' => $this->currency($accommodation),
            'rooms' => $rooms->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $stay
     * @return array{token: string, expires_at: string}
     */
    public function hold(Accommodation $accommodation, array $stay, string $clientIp): array
    {
        [$checkin, $checkout, $adults, $children] = $this->stay($stay);

        $items = collect($stay['items']);
        $this->guardAbuse($accommodation, $clientIp, $items->count());

        $types = RoomType::where('accommodation_id', $accommodation->id)
            ->whereIn('id', $items->pluck('room_type_id'))
            ->get()->keyBy('id');
        abort_if($items->contains(fn (array $item) => ! $types->has($item['room_type_id'])), 404, 'La habitación no existe.');

        $lines = $this->distribute($items, $types, $adults, $children)->map(function (array $line) use ($checkin, $checkout) {
            $plan = $this->pricing->plansFor($line['type'])->firstWhere('id', (int) $line['rate_plan_id']);
            abort_if($plan === null, 404, 'La tarifa no existe.');

            $quote = $this->pricing->quoteStay($line['type'], $plan, $checkin, $checkout, $line['adults'], $line['children']);
            abort_unless($this->isSellable($line['type'], $quote, $checkin, $checkout), 409, 'Esa tarifa ya no está disponible para esas fechas.');

            return [...$line, 'plan' => $plan, 'quote' => $quote];
        });

        $token = Str::random(48);
        $expiresAt = now()->addMinutes(BookingHold::TTL_MINUTES);

        DB::transaction(function () use ($accommodation, $lines, $types, $checkin, $checkout, $adults, $children, $token, $expiresAt, $clientIp) {
            // Lock de los tipos: dos holds simultáneos no eligen las mismas. Todo o nada.
            Room::whereIn('room_type_id', $types->keys())->orderBy('id')->lockForUpdate()->get();

            $free = $types->map(fn (RoomType $type) => $this->freeRooms($type, $checkin, $checkout));

            $assigned = $lines->map(function (array $line) use ($free) {
                $room = $free[$line['type']->id]->shift();
                abort_if($room === null, 409, 'Ya no quedan habitaciones de ese tipo para esas fechas.');

                return [...$line, 'room' => $room];
            });

            $hold = BookingHold::create([
                'token_hash' => BookingHold::hashToken($token),
                'accommodation_id' => $accommodation->id,
                'checkin_date' => $checkin,
                'checkout_date' => $checkout,
                'adults' => $adults,
                'children' => $children,
                'total_amount' => $assigned->sum(fn (array $line) => $line['quote']['total']),
                'currency' => $this->currency($accommodation),
                'expires_at' => $expiresAt,
                'client_ip' => $clientIp,
            ]);

            foreach ($assigned as $line) {
                $hold->rooms()->create([
                    'room_id' => $line['room']->id,
                    'room_type_id' => $line['type']->id,
                    'rate_plan_id' => $line['plan']->id,
                    'adults' => $line['adults'],
                    'children' => $line['children'],
                    'nightly_rates' => $line['quote']['nightly'],
                    'total_amount' => $line['quote']['total'],
                ]);
            }
        });

        return ['token' => $token, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /** Topes para que nadie bloquee el hotel sin intención de reservar. */
    private function guardAbuse(Accommodation $accommodation, string $clientIp, int $requested): void
    {
        $held = fn () => BookingHoldRoom::whereHas('hold', fn ($q) => $q->active());

        $byVisitor = $held()->whereHas('hold', fn ($q) => $q->where('client_ip', $clientIp))->count();
        abort_if($byVisitor + $requested > self::MAX_ROOMS_PER_VISITOR, 429, 'Ya tenés habitaciones apartadas. Terminá esa reserva o esperá unos minutos.');

        $total = Room::whereHas('roomType', fn ($q) => $q->where('accommodation_id', $accommodation->id))->whereNull('archived_at')->count();
        $byHotel = $held()->whereHas('hold', fn ($q) => $q->where('accommodation_id', $accommodation->id))->count();
        abort_if(
            $byHotel + $requested > max(self::MAX_ROOMS, (int) ceil($total * self::MAX_HOTEL_SHARE)),
            429,
            'Hay muchas reservas en curso. Probá de nuevo en unos minutos.'
        );
    }

    /**
     * Reparte los huéspedes: al menos un adulto por habitación y primero las de
     * mayor capacidad. Si no entran, la selección no sirve.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  Collection<int, RoomType>  $types
     * @return Collection<int, array{type: RoomType, rate_plan_id: int, adults: int, children: int}>
     */
    private function distribute(Collection $items, Collection $types, int $adults, int $children): Collection
    {
        $lines = $items
            ->map(fn (array $item) => [
                'type' => $types[$item['room_type_id']],
                'rate_plan_id' => (int) $item['rate_plan_id'],
                'adults' => 1,
                'children' => 0,
            ])
            ->sortByDesc(fn (array $line) => (int) $line['type']->max_occupancy)
            ->values()
            ->all();

        $capacity = array_sum(array_map(fn (array $line) => (int) $line['type']->max_occupancy, $lines));
        abort_if(
            $adults < count($lines) || $adults + $children > $capacity || in_array(0, array_map(fn (array $l) => (int) $l['type']->max_occupancy, $lines), true),
            422,
            'Los huéspedes no entran en esas habitaciones: cada una necesita un adulto y no puede superar su capacidad.'
        );

        $spare = $adults - count($lines);

        foreach ([['adults', $spare], ['children', $children]] as [$field, $left]) {
            foreach ($lines as $i => $line) {
                $room = (int) $line['type']->max_occupancy - $line['adults'] - $line['children'];
                $seats = min($room, $left);
                $lines[$i][$field] += $seats;
                $left -= $seats;
            }
        }

        return collect($lines);
    }

    /** @return array<string, mixed> */
    public function showHold(Accommodation $accommodation, string $token): array
    {
        return $this->summary($this->findHold($accommodation, $token));
    }

    /**
     * Crea la solicitud. Repetir el envío devuelve la misma reserva.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function confirm(Accommodation $accommodation, string $token, array $data): array
    {
        $hold = $this->findHold($accommodation, $token);

        try {
            $reservations = DB::transaction(function () use ($hold, $accommodation, $data) {
                /** @var BookingHold $locked */
                $locked = BookingHold::whereKey($hold->id)->lockForUpdate()->firstOrFail();

                if ($locked->submitted_at !== null) {
                    return null;
                }

                abort_if($locked->isExpired(), 410, 'El tiempo para completar la reserva venció.');
                $this->guardPendingByEmail($accommodation, (string) $data['email']);

                $reservations = $this->createReservations($accommodation, $locked, $data);
                $locked->update(['submitted_at' => now()]);

                return $reservations;
            });
        } catch (QueryException $e) {
            abort_if($e->getCode() === self::EXCLUSION_VIOLATION, 409, 'La habitación dejó de estar disponible.');

            throw $e;
        }

        if ($reservations !== null) {
            $this->notify($accommodation, $reservations->first());
        }

        return $this->summary($hold->refresh());
    }

    private function guardPendingByEmail(Accommodation $accommodation, string $email): void
    {
        $pending = Reservation::where('accommodation_id', $accommodation->id)
            ->where('status_id', Reservation::STATUS_PENDING)
            ->whereNull('cancelled_at')
            ->whereHas('guest', fn ($q) => $q->whereRaw('LOWER(email) = ?', [strtolower(trim($email))]))
            ->get()
            ->unique(fn (Reservation $r) => $r->booking_group_id ?? $r->id)
            ->count();

        abort_if($pending >= self::MAX_PENDING_PER_EMAIL, 429, 'Ya tenés solicitudes esperando respuesta del hotel.');
    }

    /**
     * Una reserva por habitación, unidas por grupo y con un mismo vencimiento.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Reservation>
     */
    private function createReservations(Accommodation $accommodation, BookingHold $hold, array $data): Collection
    {
        $guest = Guest::create([
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'email' => $data['email'],
            'phone' => trim($data['phone']),
        ]);

        $nights = (int) $hold->checkin_date->diffInDays($hold->checkout_date);
        $groupId = (string) Str::uuid();
        $expiresAt = BookingDeadline::for($accommodation, $hold->checkin_date);

        return $hold->rooms()->get()->map(function (BookingHoldRoom $line) use ($accommodation, $hold, $guest, $data, $nights, $groupId, $expiresAt) {
            $reservation = new Reservation([
                'accommodation_id' => $accommodation->id,
                'guest_id' => $guest->id,
                'room_id' => $line->room_id,
                'room_type_id' => $line->room_type_id,
                'rate_plan_id' => $line->rate_plan_id,
                'channel_id' => self::CHANNEL_ENGINE,
                'status_id' => Reservation::STATUS_PENDING,
                'confirmation_number' => $this->reservations->newConfirmationNumber(),
                'checkin_date' => $hold->checkin_date,
                'checkout_date' => $hold->checkout_date,
                'adults' => $line->adults,
                'children' => $line->children,
                'rate_amount' => (int) round($line->total_amount / $nights),
                'total_amount' => $line->total_amount,
                'nightly_rates' => $line->nightly_rates,
                'rate_is_manual' => false,
                'currency' => $hold->currency,
                'guarantee_type' => 'none',
                'special_requests' => $data['special_requests'] ?? null,
                'booking_group_id' => $groupId,
                'request_expires_at' => $expiresAt,
            ]);

            $reservation->fillLegacyColumns($hold->checkin_date, $hold->checkout_date, $nights, $hold->currency, 0);
            $reservation->save();
            $reservation->travelers()->attach($guest->id, ['is_primary' => true]);

            $this->reservations->adjustAvailability($line->room_type_id, $hold->checkin_date, $hold->checkout_date, -1);
            $line->update(['reservation_id' => $reservation->id]);

            return $reservation;
        });
    }

    private function notify(Accommodation $accommodation, Reservation $reservation): void
    {
        Mail::to($reservation->guest->email)->queue(new BookingGuestMail($reservation, BookingGuestMail::RECEIVED));

        foreach ($this->hotelRecipients($accommodation) as $email) {
            Mail::to($email)->queue(new BookingRequestReceivedMail($reservation));
        }
    }

    /** @return list<string> */
    private function hotelRecipients(Accommodation $accommodation): array
    {
        $emails = $accommodation->account_id === null ? [] : User::query()
            ->where('user_type', User::TYPE_ACCOUNT)
            ->where('account_id', $accommodation->account_id)
            ->where(fn ($q) => $q->whereDoesntHave('accommodations')
                ->orWhereHas('accommodations', fn ($a) => $a->where('accommodations.id', $accommodation->id)))
            ->pluck('email')->all();

        return collect([$accommodation->email, ...$emails])
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()->values()->all();
    }

    private function findHold(Accommodation $accommodation, string $token): BookingHold
    {
        $hold = BookingHold::where('token_hash', BookingHold::hashToken($token))
            ->where('accommodation_id', $accommodation->id)
            ->first();

        abort_if($hold === null, 404, 'La reserva no existe.');

        return $hold;
    }

    /** @return array<string, mixed> */
    private function summary(BookingHold $hold): array
    {
        $lines = $hold->rooms()->with(['roomType.descriptions', 'ratePlan', 'reservation'])->get();
        $first = $lines->first();
        $reservation = $first?->reservation;

        return [
            'status' => $hold->submitted_at !== null ? 'submitted' : ($hold->isExpired() ? 'expired' : 'active'),
            'expires_at' => $hold->expires_at->toIso8601String(),
            'checkin' => $hold->checkin_date->toDateString(),
            'checkout' => $hold->checkout_date->toDateString(),
            'nights' => (int) $hold->checkin_date->diffInDays($hold->checkout_date),
            'adults' => $hold->adults,
            'children' => $hold->children,
            'room' => $first?->roomType?->spanishName() ?? 'Habitación',
            'plan' => $this->planSummary($first->ratePlan),
            'rooms' => $lines->map(fn (BookingHoldRoom $line) => [
                'name' => $line->roomType?->spanishName() ?? 'Habitación',
                'plan' => $this->planSummary($line->ratePlan),
                'adults' => $line->adults,
                'children' => $line->children,
                'total' => $line->total_amount / 100,
                'confirmation_number' => $line->reservation?->confirmation_number,
            ])->values()->all(),
            'total' => $hold->total_amount / 100,
            'currency' => $hold->currency,
            'confirmation_number' => $reservation?->confirmation_number,
            'request_expires_at' => $reservation?->request_expires_at?->toIso8601String(),
        ];
    }

    /** @return array{id: int, name: string, cancellation: ?string, meal_plan: ?string} */
    private function planSummary(RatePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => (string) $plan->name,
            'cancellation' => $plan->cancellation,
            'meal_plan' => $plan->meal_plan,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function sellablePlans(RoomType $type, Carbon $checkin, Carbon $checkout, int $adults, int $children): Collection
    {
        return $this->pricing->plansFor($type)
            ->map(function (RatePlan $plan) use ($type, $checkin, $checkout, $adults, $children) {
                $quote = $this->pricing->quoteStay($type, $plan, $checkin, $checkout, $adults, $children);

                return $this->isSellable($type, $quote, $checkin, $checkout)
                    ? [...$this->planSummary($plan), 'total' => $quote['total'] / 100]
                    : null;
            })
            ->filter();
    }

    /** @param  array<string, mixed>  $quote */
    private function isSellable(RoomType $type, array $quote, Carbon $checkin, Carbon $checkout): bool
    {
        $nights = (int) $checkin->diffInDays($checkout);

        return $quote['total'] !== null
            && $quote['total'] > 0
            && $quote['closed'] === []
            && ($quote['min_stay'] === null || $nights >= $quote['min_stay'])
            && ! $this->isClosed($type, $checkin, $checkout);
    }

    /** Cierre manual del hotel en la grilla de disponibilidad. */
    private function isClosed(RoomType $type, Carbon $checkin, Carbon $checkout): bool
    {
        return RoomInventory::isClosed((int) $type->id, $checkin, $checkout);
    }

    private function fits(RoomType $type, int $adults, int $children): bool
    {
        return $type->max_occupancy !== null && (int) $type->max_occupancy >= $adults + $children;
    }

    /** @return Collection<int, Room> */
    private function freeRooms(RoomType $type, Carbon $checkin, Carbon $checkout): Collection
    {
        return RoomInventory::freeRooms((int) $type->id, $checkin, $checkout);
    }

    /**
     * @param  array<string, mixed>  $stay
     * @return array{0: Carbon, 1: Carbon, 2: int, 3: int}
     */
    private function stay(array $stay): array
    {
        return [
            Carbon::parse($stay['checkin']),
            Carbon::parse($stay['checkout']),
            (int) $stay['adults'],
            (int) ($stay['children'] ?? 0),
        ];
    }

    private function currency(Accommodation $accommodation): string
    {
        return $this->reservations->currencyOf($accommodation->currency_id);
    }
}
