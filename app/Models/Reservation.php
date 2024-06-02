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
}
