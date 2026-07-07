<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomTypeService extends Model
{
    protected $fillable = [
        'room_type_id',
        'service_id',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
