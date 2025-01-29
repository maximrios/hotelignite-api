<?php

namespace App\Models;

use Attribute;
use Illuminate\Database\Eloquent\Model;

class Guest extends Model
{
    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'guest_id', 'id');
    }

    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
