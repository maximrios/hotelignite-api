<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rate extends Model
{
    protected $fillable = [
        'rate_plan_id',
        'room_type_id',
        'date',
        'price',
        'currency',
        'min_stay',
        'max_stay',
        'stop_sell',
    ];

    protected $casts = [
        'date' => 'date',
        'price' => 'integer',
        'min_stay' => 'integer',
        'max_stay' => 'integer',
        'stop_sell' => 'boolean',
    ];

    /** Planes legacy (uno por tipo): el tipo del precio sale del plan. */
    protected static function booted(): void
    {
        static::creating(function (Rate $rate) {
            if ($rate->room_type_id === null) {
                $rate->room_type_id = RatePlan::whereKey($rate->rate_plan_id)->value('room_type_id');
            }
        });
    }

    public function ratePlan()
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * Devuelve el precio en unidad monetaria (no centavos).
     */
    public function getPriceDecimalAttribute(): float
    {
        return $this->price / 100;
    }
}
