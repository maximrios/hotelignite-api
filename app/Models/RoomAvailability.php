<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomAvailability extends Model
{
    protected $table = 'room_availability';

    protected $fillable = [
        'room_type_id',
        'date',
        'available',
        'total',
        'closed',
        'closed_to_arrival',
        'closed_to_departure',
        'min_stay',
        'max_stay',
    ];

    protected $casts = [
        'date'                => 'date',
        'closed'              => 'boolean',
        'closed_to_arrival'   => 'boolean',
        'closed_to_departure' => 'boolean',
        'min_stay'            => 'integer',
        'max_stay'            => 'integer',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
