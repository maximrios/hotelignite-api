<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    use BelongsToAccommodation;

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
        'code',
        'max_adults',
        'max_children',
        'extra_adult_price',
        'extra_child_price',
        'single_use_discount',
    ];

    /** Nombre en español (vive en `room_type_descriptions`); null si no tiene. */
    public function spanishName(): ?string
    {
        $descriptions = $this->relationLoaded('descriptions') ? $this->descriptions : $this->descriptions()->get();

        return ($descriptions->firstWhere('language_id', 'es') ?? $descriptions->first())?->name;
    }

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
