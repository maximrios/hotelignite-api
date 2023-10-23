<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    protected $fillable = [
        'name'
    ];
    //
    public function accommodations()
    {
        return $this->hasMany(Accommodation::class);
    }
}
