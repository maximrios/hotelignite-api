<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasUuids;

    public function accommodation() {
        return $this->hasOne(Accommodation::class, 'id', 'accommodation_id');
    }

    public function tour() {
        return $this->hasOne(Tour::class, 'id', 'tour_id');
    }

    
}
