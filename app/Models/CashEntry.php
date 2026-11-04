<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento del libro de caja. Sólo se agrega: `updating` y `deleting` abortan.
 * Un error se corrige con un asiento inverso (`reverses_entry_id`).
 *
 * El monto es positivo; el sentido va en `direction` (in/out).
 */
class CashEntry extends Model
{
    public const METHOD_CASH = 'cash';

    public const METHODS = ['cash', 'transfer', 'debit', 'credit', 'mercadopago', 'other'];

    public const CURRENCIES = ['ARS', 'USD'];

    public const UPDATED_AT = null;

    protected $fillable = [
        'accommodation_id', 'cash_shift_id', 'cash_category_id', 'reservation_id', 'direction',
        'amount', 'currency', 'method', 'reservation_amount', 'concept', 'reference', 'occurred_on',
        'reverses_entry_id', 'idempotency_key', 'created_by_user_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'reservation_amount' => 'integer',
        'occurred_on' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El libro de caja no se edita: anulá el movimiento.'));
        static::deleting(fn () => throw new \LogicException('El libro de caja no se borra: anulá el movimiento.'));
    }

    /** +monto si es ingreso, −monto si es egreso. */
    public function signedAmount(): int
    {
        return $this->direction === CashCategory::IN ? $this->amount : -$this->amount;
    }

    public function isCash(): bool
    {
        return $this->method === self::METHOD_CASH;
    }

    public function category()
    {
        return $this->belongsTo(CashCategory::class, 'cash_category_id');
    }

    public function shift()
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reversed()
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }
}
