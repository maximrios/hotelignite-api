<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationPolicy extends Model
{
    protected $table = 'accommodations_policies';
    
    protected $fillable = [
        'accommodation_id',
        'policy_id',
        'language_id',
        'description'
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function policy()
    {
        return $this->belongsTo(Policy::class);
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}

