<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'subtitle',
        'slug',
        'description',
        'price',
        'currency',
        'billing_period',
        'trial_days',
        'sort_order',
        'is_public',
        'enabled',
    ];

    protected $casts = [
        'price' => 'integer',
        'trial_days' => 'integer',
        'sort_order' => 'integer',
        'is_public' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function features()
    {
        return $this->belongsToMany(Feature::class, 'plan_feature')
            ->withPivot('limit')
            ->withTimestamps();
    }

    /**
     * ¿El plan incluye esta feature?
     */
    public function hasFeature(string $slug): bool
    {
        return $this->features->contains('slug', $slug);
    }

    /**
     * Límite de una feature (null = ilimitado o no incluida).
     */
    public function featureLimit(string $slug): ?int
    {
        return $this->features->firstWhere('slug', $slug)?->pivot->limit;
    }
}
