<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Invitación al flujo de alta de asociados. Ver `.claude/skills/invitations.md`.
 *
 * El token viaja al email en claro; en la base solo guardamos su hash. El token
 * prueba que quien lo tiene recibió el mail en esa casilla — nada más (§6).
 */
class Invitation extends Model
{
    protected $fillable = [
        'client_id',
        'accommodation_id',
        'email',
        'contact_name',
        'accommodation_name',
        'token_hash',
        'expires_at',
        'accepted_at',
        'accepted_by_user_id',
        'declined_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    /**
     * Columnas generadas por MySQL (emulan los índices únicos parciales). No se
     * escriben nunca a mano — se ocultan para que no aparezcan en respuestas.
     */
    protected $hidden = [
        'token_hash',
        'pending_email',
        'pending_accommodation_id',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * Token crudo (viaja al email). Se descarta tras enviarlo; solo persiste su hash.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')->whereNull('declined_at');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->declined_at === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * ¿Se puede actuar sobre ella? (pendiente y no vencida).
     */
    public function isUsable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /**
     * Etiqueta de estado para el lado público (sin exponer datos).
     */
    public function statusLabel(): string
    {
        if ($this->accepted_at !== null) {
            return 'accepted';
        }
        if ($this->declined_at !== null) {
            return 'declined';
        }

        return $this->isExpired() ? 'expired' : 'pending';
    }

    public function markAccepted(User $user): void
    {
        $this->forceFill([
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
        ])->save();
    }

    public function markDeclined(): void
    {
        $this->forceFill(['declined_at' => now()])->save();
    }
}
