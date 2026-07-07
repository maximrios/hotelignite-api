<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rate extends Model
{
    protected $fillable = [
        'rate_plan_id',
        'date',
        'price',
        'currency',
        'min_stay',
        'max_stay',
    ];

    protected $casts = [
        'date' => 'date',
        'price' => 'integer',
        'min_stay' => 'integer',
        'max_stay' => 'integer',
    ];

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
