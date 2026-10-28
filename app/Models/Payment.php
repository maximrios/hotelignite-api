<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un cobro. `(provider, provider_payment_id)` es único: un webhook repetido no duplica. */
class Payment extends Model
{
    protected $fillable = [
        'subscription_id',
        'payer_type',
        'payer_id',
        'amount',
        'currency',
        'status',
        'provider',
        'provider_payment_id',
        'concept',
        'paid_at',
        'raw',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'datetime',
        'raw' => 'array',
    ];

    public function payer()
    {
        return $this->morphTo();
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
