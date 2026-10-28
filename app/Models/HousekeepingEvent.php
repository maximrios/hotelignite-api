<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use Closure;
use Illuminate\Database\Eloquent\Model;

/** Historial inmutable de housekeeping por habitación. */
class HousekeepingEvent extends Model
{
    use BelongsToAccommodation;

    public const UPDATED_AT = null;

    public const TYPES = [
        'status_changed', 'note_changed', 'task_created', 'task_assigned', 'task_started',
        'task_finished', 'task_cancelled', 'inspected', 'problem_reported', 'dnd_set', 'dnd_cleared',
    ];

    protected $fillable = [
        'accommodation_id', 'room_id', 'task_id', 'type', 'from_status', 'to_status',
        'user_id', 'staff_id', 'source', 'note',
    ];

    protected $casts = ['created_at' => 'datetime'];

    /** Mientras es > 0, el observer de Room no registra: el módulo graba su propio evento, con más detalle. */
    private static int $silence = 0;

    public static function silently(Closure $callback): mixed
    {
        self::$silence++;

        try {
            return $callback();
        } finally {
            self::$silence--;
        }
    }

    public static function isSilenced(): bool
    {
        return self::$silence > 0;
    }

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function task()
    {
        return $this->belongsTo(HousekeepingTask::class, 'task_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function staff()
    {
        return $this->belongsTo(HousekeepingStaff::class, 'staff_id');
    }
}
