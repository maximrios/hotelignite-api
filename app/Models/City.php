<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function accommodations()
    {
        return $this->hasMany(Accommodation::class);
    }

    /**
     * `cities.state_id` es legacy: int NOT NULL DEFAULT 0 y sin FK. Las filas sin
     * provincia traen 0, no NULL, así que la relación no resuelve y devuelve
     * null — que es lo deseado, pero ojo: el 0 no es un id, es "sin dato".
     */
    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function tours()
    {
        return $this->belongsToMany(Tour::class);
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('order');
    }
}
