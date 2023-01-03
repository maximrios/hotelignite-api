<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationService extends Model
{
    //
    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }
}
