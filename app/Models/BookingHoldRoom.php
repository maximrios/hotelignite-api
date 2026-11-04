<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una habitación física dentro de una reserva temporal, con su precio cerrado. */
class BookingHoldRoom extends Model
{
    protected $fillable = [
        'booking_hold_id',
        'room_id',
        'room_type_id',
        'rate_plan_id',
        'adults',
        'children',
        'nightly_rates',
        'total_amount',
        'reservation_id',
    ];

    protected $casts = ['nightly_rates' => 'array'];

    public function hold(): BelongsTo
    {
        return $this->belongsTo(BookingHold::class, 'booking_hold_id');
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
