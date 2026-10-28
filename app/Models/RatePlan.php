<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RatePlan extends Model
{
    protected $fillable = [
        'accommodation_id',
        'parent_id',
        'is_base',
        'adjustment_type',
        'adjustment_value',
        'room_type_id', // legacy: un plan por tipo. Usar roomTypes().
        'name',
        'code',
        'cancellation',
        'meal_plan',
        'includes_breakfast', // legacy — derivar de meal_plan !== 'ro'
        'enabled',
    ];

    protected $casts = [
        'includes_breakfast' => 'boolean',
        'enabled' => 'boolean',
        'is_base' => 'boolean',
        'adjustment_value' => 'integer',
    ];

    /**
     * Compatibilidad con los que crean planes "por tipo" (endpoints legacy, MCP):
     * se completa el alojamiento y la asignación al tipo del modelo nuevo.
     */
    protected static function booted(): void
    {
        static::saving(function (RatePlan $plan) {
            if ($plan->accommodation_id === null && $plan->room_type_id !== null) {
                $plan->accommodation_id = RoomType::whereKey($plan->room_type_id)->value('accommodation_id');
            }
        });

        static::created(function (RatePlan $plan) {
            if ($plan->room_type_id !== null) {
                $plan->roomTypes()->syncWithoutDetaching([$plan->room_type_id]);
            }
        });
    }

    /** Tipos donde se vende el plan. */
    public function roomTypes()
    {
        return $this->belongsToMany(RoomType::class, 'rate_plan_room_type')->withTimestamps();
    }

    /** Plan del que deriva (el base del hotel). */
    public function parent()
    {
        return $this->belongsTo(RatePlan::class, 'parent_id');
    }

    /** Precio por noche de este plan a partir del precio base, en centavos. */
    public function derive(int $basePrice): int
    {
        return match ($this->adjustment_type) {
            'percent' => (int) round($basePrice * (100 + (int) $this->adjustment_value) / 100),
            'amount' => max(0, $basePrice + (int) $this->adjustment_value),
            default => $basePrice,
        };
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function rates()
    {
        return $this->hasMany(Rate::class);
    }

    public function getIncludesBreakfastAttribute(): bool
    {
        return in_array($this->meal_plan, ['bb', 'hb', 'fb', 'ai']);
    }
}
