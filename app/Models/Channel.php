<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    protected $fillable = [
        'name',
        'business_type',
        'connection_type',
        'code',
        'email',
        'phone',
        'web',
        'ota',
        'commission_rate',
        'enabled',
    ];

    protected $casts = [
        'ota'             => 'boolean',
        'enabled'         => 'boolean',
        'commission_rate' => 'float',
    ];

    public function tours()
    {
        return $this->hasMany(Tour::class, 'channel_id', 'id');
    }
    
}
