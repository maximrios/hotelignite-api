<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plan contratado por un alojamiento o un client. El plan vigente se refleja en
 * `plan_id` del suscriptor, que es lo que consultan los permisos.
 */
class Subscription extends Model
{
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELED = 'canceled';

    /** Estados que cuentan como "la suscripción actual" (índice único parcial). */
    public const LIVE_STATUSES = [self::STATUS_TRIALING, self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_PAST_DUE];

    public const METHOD_MERCADOPAGO = 'mercadopago';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_PURCHASE_ORDER = 'purchase_order';

    protected $fillable = [
        'plan_id',
        'status',
        'billing_period',
        'price',
        'currency',
        'payment_method',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'purchase_order_ref',
        'provider_subscription_id',
        'canceled_at',
    ];

    protected $casts = [
        'price' => 'integer',
        'current_period_start' => 'date',
        'current_period_end' => 'date',
        'trial_ends_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function subscriber()
    {
        return $this->morphTo();
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }
}
