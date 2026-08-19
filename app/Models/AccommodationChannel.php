<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pivot Accommodation ↔ Channel: la conexión de un alojamiento con un canal
 * de distribución, con sus condiciones propias (comisión, código externo).
 */
class AccommodationChannel extends Model
{
    protected $fillable = [
        'accommodation_id',
        'channel_id',
        'enabled',
        'commission_rate',
        'external_code',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'commission_rate' => 'float',
    ];

    /**
     * Espeja el default de la columna: sin esto, una conexión recién creada
     * tiene `enabled === null` en memoria hasta releerla de la BD.
     */
    protected $attributes = [
        'enabled' => true,
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Comisión que aplica a este alojamiento en este canal: la negociada acá
     * si existe, si no el default del catálogo. `null` = sin comisión definida.
     */
    public function effectiveCommissionRate(): ?float
    {
        return $this->commission_rate ?? $this->channel?->commission_rate;
    }
}
