<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use BelongsToAccommodation;

    protected $fillable = [
        'room_type_id',
        'accommodation_id',
        'number',
        'floor',
        'status',
        'housekeeping_status',
        'maintenance_note',
        'notes',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function isAvailable(): bool
    {
        return in_array($this->housekeeping_status, ['vc', 'vd'])
            && ! in_array($this->housekeeping_status, ['ooo', 'oos']);
    }

    public function isOutOfOrder(): bool
    {
        return $this->housekeeping_status === 'ooo';
    }
}
