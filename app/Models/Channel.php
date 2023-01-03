<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    public function tours()
    {
        return $this->hasMany(Tour::class, 'channel_id', 'id');
    }
    
}
