<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Client externo (agencia, gobierno, empresa) con acceso de solo lectura a
 * los accommodations relacionados vía el pivote `accommodation_client`.
 */
class Client extends Model
{
    use SoftDeletes;

    public const TYPE_AGENCY = 'agency';

    public const TYPE_GOVERNMENT = 'government';

    public const TYPE_COMPANY = 'company';

    public const TYPE_CHAMBER = 'chamber';

    public const TYPES = [self::TYPE_AGENCY, self::TYPE_GOVERNMENT, self::TYPE_COMPANY, self::TYPE_CHAMBER];

    /**
     * Compran con presupuesto → orden de compra → transferencia, no con tarjeta,
     * y no pueden pagar excedentes que no tienen presupuestados.
     */
    public const PURCHASE_ORDER_TYPES = [self::TYPE_GOVERNMENT, self::TYPE_CHAMBER];

    protected $fillable = [
        'name',
        'type',
        'channel_id',
        'email',
        'phone',
        'active',
        'rate_limit_per_minute',
        'plan_id',
        'legal_name',
        'tax_id',
        'iva_condition',
    ];

    protected $casts = [
        'active' => 'boolean',
        'rate_limit_per_minute' => 'integer',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Canal con el que se atribuyen las consultas y reservas que genera este
     * client. `null` = todavía no opera como canal propio.
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class);
    }

    public function accommodations()
    {
        return $this->belongsToMany(Accommodation::class, 'accommodation_client')
            ->withPivot(['status', 'agreed_at', 'verified_at', 'verified_by_user_id', 'invitation_id'])
            ->withTimestamps();
    }

    public function invitations()
    {
        return $this->hasMany(Invitation::class);
    }

    public function apiKeys()
    {
        return $this->hasMany(ClientApiKey::class);
    }

    /** Plan vigente; lo mantiene la suscripción. */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscriptions()
    {
        return $this->morphMany(Subscription::class, 'subscriber');
    }

    public function invoices()
    {
        return $this->morphMany(Invoice::class, 'payer');
    }

    public function aiCreditTransactions()
    {
        return $this->morphMany(AiCreditTransaction::class, 'owner');
    }

    /** Mismo contrato que `Accommodation::hasFeature()`: sin plan, nada. */
    public function hasFeature(string $slug): bool
    {
        return (bool) $this->plan?->hasFeature($slug);
    }

    /** `null` = ilimitado o no incluida; mirar `hasFeature()` para distinguir. */
    public function featureLimit(string $slug): ?int
    {
        return $this->plan?->featureLimit($slug);
    }

    public function paysWithPurchaseOrder(): bool
    {
        return in_array($this->type, self::PURCHASE_ORDER_TYPES, true);
    }

    /**
     * Cómo se llama el vínculo que el hotelero acepta al invitarlo. La invitación
     * es una sola para todos los clients; lo único que cambia es esta frase. La
     * comparten el mail, la pantalla de aceptación del PMS y el padrón del portal.
     *
     * `pitch` lleva `:client` y `:accommodation`.
     *
     * @return array{agreed: string, not_agreed: string, action: string, pitch: string}
     */
    public function relationshipVocabulary(): array
    {
        return match ($this->type) {
            self::TYPE_GOVERNMENT => [
                'agreed' => 'Adherido',
                'not_agreed' => 'Sin adherir',
                'action' => 'Invitar a adherir',
                'pitch' => ':client te invita a sumar :accommodation a su padrón de alojamientos.',
            ],
            self::TYPE_CHAMBER => [
                'agreed' => 'Socio',
                'not_agreed' => 'No socio',
                'action' => 'Invitar a asociarse',
                'pitch' => ':client te invita a sumar :accommodation como socio de la cámara.',
            ],
            default => [
                'agreed' => 'Con convenio',
                'not_agreed' => 'Sin convenio',
                'action' => 'Invitar a convenio',
                'pitch' => ':client quiere trabajar con :accommodation y te propone un convenio.',
            ],
        };
    }

    public function invitationPitch(?string $accommodationName): string
    {
        return strtr($this->relationshipVocabulary()['pitch'], [
            ':client' => $this->name,
            ':accommodation' => $accommodationName ?: 'tu alojamiento',
        ]);
    }

    /**
     * Rate limit (requests/minuto) del client. Usa el tier propio si está
     * seteado; si no, el default de config (`api.client_default_rpm`).
     */
    public function rateLimit(): int
    {
        return $this->rate_limit_per_minute ?: (int) config('api.client_default_rpm', 60);
    }
}
