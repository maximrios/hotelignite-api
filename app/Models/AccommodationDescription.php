<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationDescription extends Model
{
    //
    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class, 'accommodation_id', 'id');
    }
}
