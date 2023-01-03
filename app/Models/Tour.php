<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tour extends Model
{
    public function images()
    {
        return $this->hasMany(TourImage::class, 'tour_id', 'id');
    }
    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'id');
    }
}
