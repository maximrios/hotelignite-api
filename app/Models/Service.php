<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'enabled',
        'type',
        'is_highlighted',
        'created_at',
        'updated_at',
    ];

    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, 'accommodation_services', 'service_id', 'accommodation_id');
    }
}
