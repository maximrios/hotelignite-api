<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomTypeDescription extends Model
{
    protected $fillable = [
        'name',
        'description',
        'language_id',
        'room_type_id',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
