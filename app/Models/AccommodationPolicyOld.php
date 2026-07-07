<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationPolicyOld extends Model
{
    protected $table = 'accommodation_policy_links';

    protected $fillable = [
        'accommodation_id',
        'policy_id',
        'language_id',
        'description',
        'created_at',
        'updated_at',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function policy()
    {
        return $this->belongsTo(Policy::class);
    }
}
