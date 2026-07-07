<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    protected $fillable = [
        'size',
        'max_occupancy',
        'standard_occupancy',
        'quantity_max',
        'quantity',
        'status',
        'accommodation_id',
        'category_id',
        'slug',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('order');
    }

    public function category()
    {
        return $this->belongsTo(RoomCategory::class, 'category_id', 'id');
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, RoomTypeService::class, 'room_type_id', 'service_id');
    }

    public function descriptions()
    {
        return $this->hasMany(RoomTypeDescription::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function beds()
    {
        return $this->hasMany(RoomTypeBed::class);
    }

    public function ratePlans()
    {
        return $this->hasMany(RatePlan::class);
    }

    public function availability()
    {
        return $this->hasMany(RoomAvailability::class);
    }
}
