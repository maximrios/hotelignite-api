<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomTypeBed extends Model
{
    // Valores válidos definidos por la migración 2026_05_21_000006
    public const TYPES = [
        'king', 'queen', 'double', 'twin', 'single', 'sofa_bed', 'bunk_bed', 'crib',
    ];

    protected $fillable = [
        'room_type_id',
        'type',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
