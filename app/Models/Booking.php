<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasUuids;

    protected $fillable = [
        'accommodation_id',
        'room_id',
        'tour_id',
        'checkin',
        'checkout',
        'adults',
        'childrens',
        'name',
        'lastname',
        'email',
        'phone',
    ];

    protected $casts = [
        'checkin'  => 'date',
        'checkout' => 'date',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function tour()
    {
        return $this->belongsTo(Tour::class);
    }
}
