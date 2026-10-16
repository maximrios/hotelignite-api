<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Comprobante que emite HotelIgnite. Mercado Pago cobra pero no factura: la
 * factura electrónica (ARCA) es nuestra, con su CAE.
 */
class Invoice extends Model
{
    protected $fillable = [
        'payment_id',
        'type',
        'number',
        'concept',
        'amount',
        'currency',
        'issued_at',
        'cae',
        'pdf_url',
        'status',
    ];

    protected $casts = [
        'amount' => 'integer',
        'issued_at' => 'date',
    ];

    public function payer()
    {
        return $this->morphTo();
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
