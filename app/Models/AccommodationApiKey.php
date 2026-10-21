<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * API key de un alojamiento, para que un agente externo conteste en su nombre
 * (docs/accommodation-mcp-plan.md). Mismo esquema que `ClientApiKey`: el secreto
 * en claro nunca se persiste, sólo su hash (sha256) y un prefijo visible.
 *
 * Credencial completa: `ak_live_<prefix>_<secret>`. El prefijo `ak_` la distingue
 * de las de client (`tk_`) en logs y en los middlewares.
 */
class AccommodationApiKey extends Model
{
    public const PREFIX_LENGTH = 8;   // largo del identificador visible

    public const SECRET_LENGTH = 40;  // largo del secreto random

    /** Abilities que puede tener una key de alojamiento. */
    public const ABILITIES = ['mcp:read', 'inquiry:create'];

    /** Feature del plan que habilita el asistente (PlanFeatureSeeder: desde Starter). */
    public const FEATURE = 'ai_assistant';

    protected $fillable = [
        'account_id',
        'accommodation_id',
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

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Accommodation, $this> */
    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    /**
     * Genera una credencial nueva para un alojamiento. Devuelve el modelo
     * persistido y el secreto en claro (`plain`), que se muestra UNA sola vez.
     *
     * @param  list<string>  $abilities
     * @return array{model: AccommodationApiKey, plain: string}
     */
    public static function generateFor(Accommodation $accommodation, ?string $name = null, array $abilities = self::ABILITIES, ?Carbon $expiresAt = null): array
    {
        $prefix = 'ak_live_'.Str::lower(Str::random(self::PREFIX_LENGTH));
        $secret = Str::random(self::SECRET_LENGTH);
        $plain = $prefix.'_'.$secret;

        $model = self::create([
            'account_id' => $accommodation->account_id,
            'accommodation_id' => $accommodation->id,
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
