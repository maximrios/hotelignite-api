<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Caja física del alojamiento. Hoy una sola por alojamiento ("Recepción"), creada al primer uso. */
class CashRegister extends Model
{
    protected $fillable = ['accommodation_id', 'name', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function shifts()
    {
        return $this->hasMany(CashShift::class);
    }
}
