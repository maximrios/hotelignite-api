<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Categoría de evento, un nivel (docs/events-plan.md). */
class EventCategory extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'icon',
        'sort_order',
        'enabled',
    ];

    protected $attributes = [
        'sort_order' => 0,
        'enabled' => true,
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'enabled' => 'boolean',
    ];

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
