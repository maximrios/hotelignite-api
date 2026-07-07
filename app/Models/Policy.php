<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    protected $fillable = [
        'name',
        'description',
        'enabled',
    ];

    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, 'accommodation_policies', 'policy_id', 'accommodation_id');
    }
}
