<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationService extends Model
{
    protected $fillable = [
        'accommodation_id',
        'service_id',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
