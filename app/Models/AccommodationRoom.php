<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationRoom extends Model
{
    //
    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }
}
