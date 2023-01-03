<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomCategory extends Model
{
    //
    public function type()
    {
        return $this->belongsTo(RoomType::class);
    }
}
