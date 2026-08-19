<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccommodationDescription extends Model
{
    // La tabla usa nombres de timestamp legacy (no created_at/updated_at).
    public const CREATED_AT = 'created';

    public const UPDATED_AT = 'modified';

    protected $fillable = [
        'accommodation_id',
        'language_id',
        'introduction',
        'description',
        'enabled',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class, 'accommodation_id', 'id');
    }
}
