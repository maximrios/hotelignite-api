<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccommodation;
use Illuminate\Database\Eloquent\Model;

/** Personal de limpieza sin login: un nombre a quien se le asigna trabajo. */
class HousekeepingStaff extends Model
{
    use BelongsToAccommodation;

    protected $table = 'housekeeping_staff';

    protected $fillable = ['accommodation_id', 'name', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function tasks()
    {
        return $this->hasMany(HousekeepingTask::class, 'staff_id');
    }
}
