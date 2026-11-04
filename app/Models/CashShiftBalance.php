<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Fondo, esperado, contado y diferencia de un turno en una moneda. Centavos. */
class CashShiftBalance extends Model
{
    protected $fillable = [
        'cash_shift_id', 'currency', 'opening_amount', 'expected_amount', 'counted_amount', 'difference',
    ];

    protected $casts = [
        'opening_amount' => 'integer',
        'expected_amount' => 'integer',
        'counted_amount' => 'integer',
        'difference' => 'integer',
    ];
}
