<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasUuids;

    public function accommodation() {
        return $this->hasOne(Accommodation::class, 'id', 'accommodation_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class, 'guest_id', 'id');
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id', 'id');
    }

    public function getPaxAttribute()
    {
        return $this->adults + $this->childrens;
    }
}
