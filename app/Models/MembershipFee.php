<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Categoría de cuota que define un client para sus socios ("Categoría A ·
 * más de 50 habitaciones"). Mensual: se emite una por mes con `due_day` de
 * vencimiento. Ver la migración de `membership_dues`.
 */
class MembershipFee extends Model
{
    protected $fillable = ['client_id', 'name', 'amount', 'due_day', 'active'];

    protected $casts = [
        'amount' => 'integer',
        'due_day' => 'integer',
        'active' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function assignments()
    {
        return $this->hasMany(MemberFeeAssignment::class);
    }
}
