<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use BelongsToAccommodation, HasUuids;

    /** Fila de `reservations_status` (catálogo legacy). */
    public const STATUS_CONFIRMED = 2;

    public const STATUS_CHECKIN = 4;

    public const STATUS_CHECKOUT = 5;

    public const STATUS_NO_SHOW = 6;

    /** Desde esta hora local del día de llegada se puede marcar no-show. */
    public const NO_SHOW_HOUR = 20;

    protected $fillable = [
        'accommodation_id',
        'guest_id',
        'channel_id',
        'status_id',
        'room_id',
        'room_type_id',
        'rate_plan_id',
        'confirmation_number',
        'source_reference',
        'checkin_date',
        'checkout_date',
        'checkin_time',
        'checkout_time',
        'adults',
        'children',
        'extra_beds',
        'childrens', // columna legacy — usar 'children' en nuevos registros
        'rate_amount',
        'total_amount',
        'currency',
        'commission_amount',
        'guarantee_type',
        'deposit_amount',
        'deposit_date',
        'cancelled_at',
        'cancellation_reason',
        'special_requests',
        'internal_notes',
        'nightly_rates',
        'rate_is_manual',
        'vehicle_plate',
        'vehicle_description',
    ];

    protected $casts = [
        'checkin_date' => 'date',
        'checkout_date' => 'date',
        'deposit_date' => 'date',
        'cancelled_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'no_show_at' => 'datetime',
        'booked_checkout_date' => 'date',
        'nightly_rates' => 'array',
        'rate_is_manual' => 'boolean',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    /** Todos los pasajeros (parte de viajeros), titular incluido. */
    public function travelers()
    {
        return $this->belongsToMany(Guest::class, 'reservation_guests')
            ->withPivot(['is_primary', 'is_minor', 'age'])
            ->withTimestamps()
            ->orderByPivot('is_primary', 'desc')
            ->orderBy('reservation_guests.id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /** Tipo vendido; puede diferir del de la habitación asignada (upgrade). */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function getPaxAttribute(): int
    {
        // 'children' es la columna nueva; 'childrens' es la columna legacy
        return $this->adults + ($this->children ?? $this->childrens ?? 0);
    }

    public function getNightsAttribute(): int
    {
        if (! $this->checkin_date || ! $this->checkout_date) {
            return 0;
        }

        return $this->checkin_date->diffInDays($this->checkout_date);
    }

    /**
     * Fecha de llegada efectiva. Desde 2026_10_27_000001 todas las filas tienen
     * `checkin_date`; el fallback a `arrival` cubre las que siga insertando
     * código legacy (BookingController).
     */
    public function effectiveCheckin(): ?Carbon
    {
        return $this->checkin_date ?? ($this->arrival ? Carbon::parse($this->arrival) : null);
    }

    public function effectiveCheckout(): ?Carbon
    {
        return $this->checkout_date ?? ($this->departure ? Carbon::parse($this->departure) : null);
    }

    /**
     * Hoy en la hora del hotel, como fecha a medianoche en la zona de la app
     * (UTC): así se compara con `checkin_date`/`checkout_date`, que el cast
     * también deja a medianoche UTC. Con `Carbon::today()` a las 21 hs de Salta
     * ya era "mañana".
     */
    public static function today(): Carbon
    {
        return Carbon::parse(self::localNow()->toDateString());
    }

    public static function localNow(): Carbon
    {
        return Carbon::now(config('app.destination_timezone'));
    }

    /**
     * Estado operativo. Prioridad: cancelada > no-show > finalizada > (con
     * check-in) por salir / in-house > (sin check-in) por llegar.
     *
     * In-house exige check-in registrado: una reserva cuya llegada ya pasó sin
     * check-in sigue "por llegar" (llegada pendiente) hasta que se registre el
     * check-in o el no-show. Si la estadía terminó entera sin registrar nada es
     * histórica (legacy) y se muestra finalizada. Con check-in y la salida
     * vencida queda "por salir" (salida pendiente) hasta registrar el check-out.
     *
     * @return 'cancelled'|'no_show'|'out'|'departing'|'arriving'|'in'
     */
    public function derivedStatus(): string
    {
        if ($this->cancelled_at !== null) {
            return 'cancelled';
        }

        if ($this->no_show_at !== null) {
            return 'no_show';
        }

        if ($this->checked_out_at !== null || (int) $this->status_id === self::STATUS_CHECKOUT) {
            return 'out';
        }

        $checkin = $this->effectiveCheckin();
        $checkout = $this->effectiveCheckout();

        if ($checkin === null || $checkout === null) {
            return 'arriving';
        }

        $today = self::today();

        if ($this->checked_in_at !== null) {
            return $checkout->lte($today) ? 'departing' : 'in';
        }

        return $checkout->lt($today) ? 'out' : 'arriving';
    }

    /** Debía llegar (hoy o antes) y no tiene check-in. */
    public function arrivalPending(): bool
    {
        $checkin = $this->effectiveCheckin();

        return $this->derivedStatus() === 'arriving' && $checkin !== null && $checkin->lte(self::today());
    }

    /** Tiene check-in y la salida ya pasó sin registrarse. */
    public function departureOverdue(): bool
    {
        $checkout = $this->effectiveCheckout();

        return $this->derivedStatus() === 'departing' && $checkout !== null && $checkout->lt(self::today());
    }

    /**
     * Acciones de recepción que admite la reserva ahora. El repositorio aplica
     * las mismas reglas al ejecutarlas; esto es para que el PMS no ofrezca un
     * botón que va a fallar.
     *
     * @return list<string>
     */
    public function availableActions(): array
    {
        $status = $this->derivedStatus();
        $checkin = $this->effectiveCheckin();
        $checkout = $this->effectiveCheckout();
        $today = self::today();
        $now = self::localNow();
        $actions = [];

        if (! in_array($status, ['out', 'cancelled', 'no_show'], true)) {
            $actions[] = 'edit';
        }

        if ($status === 'arriving') {
            $actions[] = 'cancel';

            if ($checkin !== null && $checkout !== null && $checkin->lte($today) && $checkout->gt($today)) {
                $actions[] = 'check_in';
            }

            if ($checkin !== null && ($checkin->lt($today) || ($checkin->equalTo($today) && $now->hour >= self::NO_SHOW_HOUR))) {
                $actions[] = 'no_show';
            }
        }

        if (in_array($status, ['in', 'departing'], true)) {
            $actions[] = 'check_out';

            if ($this->checked_in_at->gt(now()->subDay())) {
                $actions[] = 'undo_check_in';
            }
        }

        if ($status === 'out' && $this->checked_out_at !== null && $this->checked_in_at !== null && $this->isLocalToday($this->checked_out_at)) {
            $actions[] = 'undo_check_out';
        }

        if ($status === 'no_show' && $this->isLocalToday($this->no_show_at)) {
            $actions[] = 'undo_no_show';
        }

        return $actions;
    }

    private function isLocalToday(Carbon $moment): bool
    {
        return $moment->copy()->setTimezone(config('app.destination_timezone'))->toDateString() === self::localNow()->toDateString();
    }
}
