<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationDescription extends Model
{
    protected $fillable = [
        'accommodation_id',
        'language_id',
        'introduction',
        'description',
        'enabled',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class, 'accommodation_id', 'id');
    }
}
