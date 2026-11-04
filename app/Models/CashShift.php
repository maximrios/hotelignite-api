<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Turno de caja: se abre con fondo y se cierra con arqueo. Ver la migración `create_cash_register_tables`. */
class CashShift extends Model
{
    protected $fillable = [
        'accommodation_id', 'cash_register_id', 'opened_by_user_id', 'opened_at',
        'closed_by_user_id', 'closed_at', 'closing_notes',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function register()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function balances()
    {
        return $this->hasMany(CashShiftBalance::class);
    }

    public function entries()
    {
        return $this->hasMany(CashEntry::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
