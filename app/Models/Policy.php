<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    protected $fillable = [
        'name'
    ];

    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, AccommodationPolicy::class, 'policy_id', 'accommodation_id')
            ->withPivot('language_id', 'description')
            ->withTimestamps();
    }

    public function accommodationPolicies()
    {
        return $this->hasMany(AccommodationPolicy::class);
    }
}





