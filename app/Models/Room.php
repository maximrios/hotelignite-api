<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use App\Observers\RoomObserver;
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
        'features',
        'archived_at',
        'inspected_at',
        'dnd_date',
        'out_of_service_until',
    ];

    protected $casts = [
        'features' => 'array',
        'archived_at' => 'datetime',
        'inspected_at' => 'datetime',
        'dnd_date' => 'date:Y-m-d',
        'out_of_service_until' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::observe(RoomObserver::class);
    }

    public function housekeepingTasks()
    {
        return $this->hasMany(HousekeepingTask::class);
    }

    public function housekeepingEvents()
    {
        return $this->hasMany(HousekeepingEvent::class);
    }

    /** Las dadas de baja no se venden ni aparecen en el rack. */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

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
