<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    protected $table = 'reservations_status';

    protected $fillable = [
        'name',
    ];

    //
    public function accommodations()
    {
        return $this->hasMany(Reservation::class);
    }
}
