<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Invitación de acceso de un user dado de alta por el staff: el link del email
 * de bienvenida le deja elegir su contraseña. Por ahora sólo users `client`.
 *
 * Mismo criterio que `Invitation`: el token viaja en claro sólo en el email, en
 * la base queda su hash. El recordatorio rota el token en la misma fila, así que
 * el link del primer email deja de andar y vale el del más reciente.
 *
 * Estados: pending → opened (abrió el link) → accepted; o expired / revoked.
 */
class UserInvitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_OPENED = 'opened';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'user_id',
        'token_hash',
        'sent_at',
        'opened_at',
        'reminded_at',
        'accepted_at',
        'revoked_at',
        'expires_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'reminded_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'token_hash',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Sin aceptar ni revocar (puede estar vencida). */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::STATUS_ACCEPTED,
            $this->revoked_at !== null => self::STATUS_REVOKED,
            $this->expires_at->isPast() => self::STATUS_EXPIRED,
            $this->opened_at !== null => self::STATUS_OPENED,
            default => self::STATUS_PENDING,
        };
    }
}
