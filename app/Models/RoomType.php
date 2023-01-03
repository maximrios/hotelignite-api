<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    //
    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }
    public function images()
    {
        return $this->hasMany(RoomImage::class, 'room_type_id', 'id');
    }
    public function category()
    {
        return $this->hasOne(RoomCategory::class, 'id', 'category_id');
    }
}
