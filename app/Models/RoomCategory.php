<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomCategory extends Model
{
    public function roomTypes()
    {
        return $this->hasMany(RoomType::class, 'category_id');
    }
}
