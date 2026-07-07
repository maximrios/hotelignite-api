<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationPolicyTranslation extends Model
{
    protected $table = 'accommodation_policy_translations';

    protected $fillable = [
        'policy_id',
        'language_id',
        'house_rules',
    ];

    public function policy()
    {
        return $this->belongsTo(AccommodationPolicy::class, 'policy_id');
    }
}
