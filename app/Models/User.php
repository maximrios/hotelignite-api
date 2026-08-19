<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    public const TYPE_ACCOUNT = 'account';

    public const TYPE_CLIENT = 'client';

    public const TYPE_PLATFORM = 'platform';

    public const TYPES = [
        self::TYPE_PLATFORM,
        self::TYPE_ACCOUNT,
        self::TYPE_CLIENT,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'user_type',
        'account_id',
        'client_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Acotamiento opcional de un user `account` a alojamientos puntuales.
     * Vacío = ve toda su cuenta (default dinámico); con filas = ve solo esos.
     * Solo tiene sentido para `account`; los otros tipos scopean por otra vía.
     */
    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, 'accommodation_user')
            ->withTimestamps();
    }

    /**
     * ¿El acotamiento por alojamiento incluye este id? Sin filas en el pivote el
     * user ve toda su cuenta (true para cualquiera); con filas, solo los acotados.
     *
     * Es el par a nivel de registro de Accommodation::scopeVisibleTo (que scopea
     * el listado). Las policies lo usan para que el acotamiento sea control de
     * acceso real: sin esto, un user acotado igual podría abrir/editar por id
     * otro alojamiento de su cuenta, y el acotamiento sería solo cosmético.
     */
    public function accommodationScopeAllows(int $accommodationId): bool
    {
        $scoped = $this->accommodations()->pluck('accommodations.id');

        return $scoped->isEmpty() || $scoped->contains($accommodationId);
    }

    public function isPlatform(): bool
    {
        return $this->user_type === self::TYPE_PLATFORM;
    }

    public function isAccount(): bool
    {
        return $this->user_type === self::TYPE_ACCOUNT;
    }

    public function isClient(): bool
    {
        return $this->user_type === self::TYPE_CLIENT;
    }

    /**
     * Normaliza la tenencia según el tipo, garantizando las invariantes de
     * docs/users-crud-plan.md: cada tipo deja colgado exactamente un id (o
     * ninguno, para platform). Es el único lugar donde se decide esto — el
     * controller y los FormRequest delegan acá para que no puedan divergir.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{user_type: string, account_id: int|null, client_id: int|null}
     */
    public static function normalizeTenancy(string $userType, array $attributes): array
    {
        return match ($userType) {
            // Un platform bypassa la tenencia: dejarle un id colgado es estado
            // inconsistente que puede filtrarse a otras queries.
            self::TYPE_PLATFORM => [
                'user_type' => self::TYPE_PLATFORM,
                'account_id' => null,
                'client_id' => null,
            ],
            self::TYPE_ACCOUNT => [
                'user_type' => self::TYPE_ACCOUNT,
                'account_id' => $attributes['account_id'] ?? null,
                'client_id' => null,
            ],
            self::TYPE_CLIENT => [
                'user_type' => self::TYPE_CLIENT,
                'account_id' => null,
                'client_id' => $attributes['client_id'] ?? null,
            ],
        };
    }

    /**
     * Revoca los tokens de Sanctum vigentes. Sin esto la baja es cosmética: el
     * token sigue autenticando hasta expirar.
     */
    public function revokeApiTokens(): void
    {
        $this->tokens()->delete();
    }
}
