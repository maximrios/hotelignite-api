<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RatePlan extends Model
{
    protected $fillable = [
        'room_type_id',
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
    ];

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
