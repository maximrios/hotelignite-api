<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Aviso de Mercado Pago ya validado. `processed_at` null = falta procesarlo (o falló). */
class MercadoPagoNotification extends Model
{
    protected $table = 'mercadopago_notifications';

    /** Tópicos que se procesan; el resto se guarda y se marca procesado. */
    public const PREAPPROVAL = 'subscription_preapproval';

    public const AUTHORIZED_PAYMENT = 'subscription_authorized_payment';

    /** Reintentos antes de dejarlo para revisión manual. */
    public const MAX_ATTEMPTS = 10;

    protected $fillable = [
        'topic',
        'resource_id',
        'request_id',
        'payload',
        'received_count',
        'attempts',
        'last_error',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
