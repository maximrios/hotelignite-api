<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationRatePolicy extends Model
{
    protected $fillable = [
        'accommodation_id',
        'rate_type',
        'cancel_days',
        'cancel_penalty',
        'no_show_penalty',
    ];

    protected $casts = [
        'cancel_penalty' => 'float',
        'no_show_penalty' => 'float',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }
}
