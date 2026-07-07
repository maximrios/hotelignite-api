<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationPolicy extends Model
{
    protected $fillable = [
        'accommodation_id',
        'checkin_from',
        'checkin_to',
        'checkout_from',
        'checkout_to',
        'min_age',
        'allow_children',
        'children_max_age',
        'allow_pets',
        'allow_smoking',
        'allow_parties',
        'payment_card',
        'payment_cash',
        'payment_transfer',
        'payment_crypto',
    ];

    protected $casts = [
        'allow_children'   => 'boolean',
        'allow_pets'       => 'boolean',
        'allow_smoking'    => 'boolean',
        'allow_parties'    => 'boolean',
        'payment_card'     => 'boolean',
        'payment_cash'     => 'boolean',
        'payment_transfer' => 'boolean',
        'payment_crypto'   => 'boolean',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function translations()
    {
        return $this->hasMany(AccommodationPolicyTranslation::class, 'policy_id');
    }
}
