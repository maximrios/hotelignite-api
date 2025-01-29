<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, 'accommodation_services', 'service_id', 'accommodation_id');
    }
}
