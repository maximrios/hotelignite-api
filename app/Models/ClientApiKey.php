<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * API key de un Client externo. El secreto en claro nunca se persiste: sólo
 * se guarda su hash (sha256) y un prefijo visible para localizar la fila.
 *
 * Credencial completa: `tk_live_<prefix>_<secret>`.
 */
class ClientApiKey extends Model
{
    public const PREFIX_LENGTH = 8;   // largo del identificador visible

    public const SECRET_LENGTH = 40;  // largo del secreto random

    protected $fillable = [
        'client_id',
        'name',
        'prefix',
        'key_hash',
        'abilities',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'key_hash',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Genera una credencial nueva para un client. Devuelve el modelo persistido
     * y el secreto en claro (`plain`), que se muestra UNA sola vez.
     *
     * @return array{model: ClientApiKey, plain: string}
     */
    public static function generateFor(Client $client, ?string $name = null, array $abilities = ['catalog:read'], ?Carbon $expiresAt = null): array
    {
        $prefix = 'tk_live_'.Str::lower(Str::random(self::PREFIX_LENGTH));
        $secret = Str::random(self::SECRET_LENGTH);
        $plain = $prefix.'_'.$secret;

        $model = self::create([
            'client_id' => $client->id,
            'name' => $name,
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $plain),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return ['model' => $model, 'plain' => $plain];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function hasAbility(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}
