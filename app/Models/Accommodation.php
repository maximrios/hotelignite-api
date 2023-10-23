<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Accommodation extends Model
{
    protected $fillable = [
        'name'
    ];
    //
    public function roomTypes()
    {
        return $this->hasMany(RoomType::class);
        //return $this->belongsToMany(RoomCategory::class, RoomType::class, 'accommodation_id', 'category_id');
    }
    public function images()
    {
        return $this->hasMany(AccommodationImage::class);
    }
    public function descriptions()
    {
        return $this->hasMany(AccommodationDescription::class);
    }
    public function type()
    {
        return $this->belongsTo(AccommodationType::class);
    }
    public function services()
    {
        return $this->belongsToMany(Service::class, AccommodationService::class, 'accommodation_id', 'service_id');
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
