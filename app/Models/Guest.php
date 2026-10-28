<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Guest extends Model
{
    // Tabla legacy: sus timestamps son `created` / `modified`.
    public const CREATED_AT = 'created';

    public const UPDATED_AT = 'modified';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'document_type',
        'document_number',
        'nationality',
        'birthdate',
        'document_country',
    ];

    protected $casts = [
        'birthdate' => 'date',
    ];

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    /** Estadías donde figura como pasajero (titular o acompañante). */
    public function stays()
    {
        return $this->belongsToMany(Reservation::class, 'reservation_guests')
            ->withPivot(['is_primary', 'is_minor', 'age'])
            // La pivote usa created_at/updated_at, no los `created`/`modified` legacy de guests.
            ->withTimestamps('created_at', 'updated_at');
    }

    /** Edad a una fecha (por defecto hoy); null sin fecha de nacimiento. */
    public function age(?Carbon $at = null): ?int
    {
        return $this->birthdate ? (int) $this->birthdate->diffInYears($at ?? now()) : null;
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
