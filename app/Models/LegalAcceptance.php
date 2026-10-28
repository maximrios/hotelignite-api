<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Aceptación de un documento legal (versión, fecha, origen). Se revoca, no se borra. */
class LegalAcceptance extends Model
{
    protected $fillable = [
        'user_id',
        'account_id',
        'document',
        'version',
        'accepted_at',
        'ip',
        'user_agent',
        'revoked_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
