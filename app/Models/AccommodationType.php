<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationType extends Model
{
    protected $fillable = ['name', 'slug'];

    public function accommodations()
    {
        return $this->hasMany(Accommodation::class, 'type_id', 'id');
    }
}
