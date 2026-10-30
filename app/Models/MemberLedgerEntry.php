<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Asiento de la cuenta corriente de un socio con su client. Sólo se agrega:
 * `updating` y `deleting` abortan. Un error se corrige con un `reversal`.
 *
 * Signo: positivo = debe (cuota, cargo), negativo = haber (pago, bonificación).
 */
class MemberLedgerEntry extends Model
{
    public const KIND_CHARGE = 'charge';

    public const KIND_PAYMENT = 'payment';

    public const KIND_CREDIT = 'credit';

    public const KIND_REVERSAL = 'reversal';

    public const METHODS = ['transferencia', 'efectivo', 'cheque', 'debito', 'mercadopago', 'otro'];

    public const UPDATED_AT = null;

    protected $fillable = [
        'client_id', 'member_type', 'member_id', 'kind', 'concept', 'amount', 'period',
        'occurred_on', 'due_on', 'method', 'reference', 'membership_fee_id',
        'reverses_entry_id', 'created_by_user_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'occurred_on' => 'date:Y-m-d',
        'due_on' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El libro de cuotas no se edita: anulá el asiento.'));
        static::deleting(fn () => throw new \LogicException('El libro de cuotas no se borra: anulá el asiento.'));
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }
}
