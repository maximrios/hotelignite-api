<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Prospecto que llega por la landing pública: quiere empezar gratis o pide una
 * demo. El staff lo contacta y hace el alta; esta fila no da acceso a nada.
 */
class Lead extends Model
{
    public const TYPE_SIGNUP = 'signup';

    public const TYPE_DEMO = 'demo';

    public const TYPES = [self::TYPE_SIGNUP, self::TYPE_DEMO];

    public const STATUSES = ['new', 'contacted', 'converted', 'discarded'];

    protected $fillable = [
        'type',
        'name',
        'email',
        'phone',
        'accommodation_name',
        'city',
        'rooms',
        'message',
    ];

    protected $casts = [
        'rooms' => 'integer',
    ];
}
