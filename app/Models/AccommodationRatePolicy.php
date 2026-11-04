<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationRatePolicy extends Model
{
    /**
     * Los mismos que `rate_plans.cancellation`: cada tarifa del PMS apunta a una
     * de estas reglas (ver migración 2026_11_03_000004).
     */
    public const TYPES = ['flexible', 'moderate', 'strict', 'non_refundable'];

    protected $fillable = [
        'accommodation_id',
        'rate_type',
        'cancel_days',
        'cancel_penalty',
        'no_show_penalty',
    ];

    protected $casts = [
        'cancel_penalty' => 'float',
        'no_show_penalty' => 'float',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }
}
