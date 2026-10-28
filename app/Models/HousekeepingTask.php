<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use Illuminate\Database\Eloquent\Model;

class HousekeepingTask extends Model
{
    use BelongsToAccommodation;

    public const TYPE_CHECKOUT = 'checkout';

    public const TYPE_ARRIVAL = 'arrival';

    public const TYPE_DAILY = 'daily';

    public const TYPE_MANUAL = 'manual';

    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'accommodation_id', 'room_id', 'date', 'type', 'priority', 'status',
        'staff_id', 'note', 'started_at', 'finished_at', 'cancelled_at',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'priority' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function staff()
    {
        return $this->belongsTo(HousekeepingStaff::class, 'staff_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::PENDING, self::IN_PROGRESS], true);
    }

    public function isManual(): bool
    {
        return $this->type === self::TYPE_MANUAL;
    }
}
