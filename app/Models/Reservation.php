<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasUuids;

    protected $fillable = [
        'accommodation_id',
        'guest_id',
        'channel_id',
        'status_id',
        'room_id',
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
    ];

    protected $casts = [
        'checkin_date'  => 'date',
        'checkout_date' => 'date',
        'deposit_date'  => 'date',
        'cancelled_at'  => 'datetime',
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

    public function room()
    {
        return $this->belongsTo(Room::class);
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
        if (!$this->checkin_date || !$this->checkout_date) {
            return 0;
        }

        return $this->checkin_date->diffInDays($this->checkout_date);
    }
}
