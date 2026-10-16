<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Libro de créditos de IA: el saldo es la suma de `amount`. Asignación del plan
 * y compras suman; el consumo resta. Sin `updated_at`: los movimientos no se editan.
 */
class AiCreditTransaction extends Model
{
    public const UPDATED_AT = null;

    public const REASON_PLAN_GRANT = 'plan_grant';

    public const REASON_PACK_PURCHASE = 'pack_purchase';

    public const REASON_USAGE = 'usage';

    public const REASON_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'amount',
        'reason',
        'period',
        'description',
        'payment_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function owner()
    {
        return $this->morphTo();
    }
}
