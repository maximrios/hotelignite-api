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
        'ota' => 'boolean',
        'enabled' => 'boolean',
        'commission_rate' => 'float',
    ];

    public function tours()
    {
        return $this->hasMany(Tour::class, 'channel_id', 'id');
    }

    /**
     * Alojamientos conectados a este canal, con las condiciones propias de
     * cada uno (comisión negociada, código externo, si está pausado).
     */
    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, AccommodationChannel::class, 'channel_id', 'accommodation_id')
            ->withPivot(['enabled', 'commission_rate', 'external_code'])
            ->withTimestamps();
    }

    /**
     * Clients que operan a través de este canal. Normalmente cero o uno: el
     * canal es la cara comercial del client, no al revés.
     */
    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function inquiries()
    {
        return $this->hasMany(Inquiry::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
