<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'module',
        'type',
        'description',
    ];

    public function plans()
    {
        return $this->belongsToMany(Plan::class, 'plan_feature')
            ->withPivot('limit')
            ->withTimestamps();
    }
}
