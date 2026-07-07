<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Accommodation extends Model
{
    protected $fillable = [
        'account_id',
        'plan_id',
        'type_id',
        'name',
        'slug',
        'email',
        'phone',
        'email_reservations',
        'phone_reservations',
        'web',
        'address',
        'postal_code',
        'city_id',
        'state_id',
        'country_id',
        'latitude',
        'longitude',
        'file_number',
        'tax_identification',
        'currency_id',
        'channel_code',
        'bank_data',
        'comment',
        'logo',
        'enabled',
        'active',
        'test',
        'expiration',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'active'  => 'boolean',
        'test'    => 'boolean',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'accommodation_client');
    }

    /**
     * Limita la consulta a los accommodations visibles para el usuario dado.
     * platform: todos · account: los de su cuenta · client: los relacionados.
     * Fail-closed: un user sin cuenta/cliente no ve nada.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isPlatform()) {
            return $query;
        }

        if ($user->isClient()) {
            return $query->whereHas('clients', fn ($q) => $q->where('clients.id', $user->client_id));
        }

        return $query->where('account_id', $user->account_id);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * ¿El plan de este alojamiento incluye la feature dada?
     * Fuente de verdad de los entitlements (plan por alojamiento, no por cuenta).
     */
    public function hasFeature(string $slug): bool
    {
        return (bool) $this->plan?->hasFeature($slug);
    }

    /**
     * Límite de una feature para este alojamiento (null = ilimitado o no incluida).
     */
    public function featureLimit(string $slug): ?int
    {
        return $this->plan?->featureLimit($slug);
    }

    /**
     * Reservas online habilitadas = el plan incluye el motor de reservas.
     */
    public function allowsOnlineBookings(): bool
    {
        return $this->hasFeature('booking_engine');
    }

    public function roomTypes()
    {
        return $this->hasMany(RoomType::class);
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('order');
    }

    public function descriptions()
    {
        return $this->hasMany(AccommodationDescription::class);
    }

    public function type()
    {
        return $this->belongsTo(AccommodationType::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, AccommodationService::class, 'accommodation_id', 'service_id');
    }

    public function policies()
    {
        return $this->belongsToMany(Policy::class, AccommodationPolicyOld::class, 'accommodation_id', 'policy_id')
            ->withPivot('language_id', 'description');
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
