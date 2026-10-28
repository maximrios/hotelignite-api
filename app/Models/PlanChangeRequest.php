<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pedido de cambio de plan de un alojamiento. Con `payment_method` mercadopago
 * lo aprueba la suscripción autorizada en Mercado Pago; sin él, el equipo a mano.
 */
class PlanChangeRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELED = 'canceled';

    protected $fillable = [
        'accommodation_id',
        'from_plan_id',
        'to_plan_id',
        'billing_period',
        'status',
        'requested_by_user_id',
        'resolved_by_user_id',
        'note',
        'resolved_at',
        'payment_method',
        'external_reference',
        'provider_subscription_id',
        'checkout_url',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function fromPlan()
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan()
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }
}
