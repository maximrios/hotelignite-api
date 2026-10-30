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

    /**
     * Sólo publican en su web a quien aceptó el vínculo (socio, adherido): para
     * ellos aparecer es lo que da la membresía. Una agencia no tiene esa palanca
     * y publica a cualquiera con dueño.
     */
    public const AGREEMENT_TO_PUBLISH_TYPES = [self::TYPE_GOVERNMENT, self::TYPE_CHAMBER];

    /** Llevan cuotas y cuenta corriente de sus socios (`member_ledger_entries`). */
    public const MEMBERSHIP_DUES_TYPES = [self::TYPE_CHAMBER];

    /** Lo dio de alta el staff desde el CRM. */
    public const ORIGIN_STAFF = 'staff';

    /** Se registró solo en el portal: confirma su email y lo verifica el staff. */
    public const ORIGIN_SIGNUP = 'signup';

    /**
     * Plan con el que entra cada tipo de client. Todo client tiene siempre un
     * plan: el free es el piso, nunca `plan_id` null.
     */
    public const FREE_PLAN_SLUGS = [
        self::TYPE_AGENCY => 'client-agencia-free',
        self::TYPE_COMPANY => 'client-agencia-free',
        self::TYPE_GOVERNMENT => 'client-padron-inicial',
        self::TYPE_CHAMBER => 'client-padron-inicial',
    ];

    protected $fillable = [
        'name',
        'type',
        'channel_id',
        'email',
        'logo_url',
        'logo_public_id',
        'phone',
        'active',
        'rate_limit_per_minute',
        'plan_id',
        'legal_name',
        'tax_id',
        'iva_condition',
        'license_number',
    ];

    protected $casts = [
        'active' => 'boolean',
        'rate_limit_per_minute' => 'integer',
        'verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Sin plan explícito, entra con el free de su tipo. Si los planes todavía
        // no se sembraron queda null y `ClientPlanSeeder` lo completa después.
        static::creating(function (Client $client) {
            $client->plan_id ??= self::freePlanIdFor($client->type);
        });
    }

    public static function freePlanIdFor(?string $type): ?int
    {
        $slug = self::FREE_PLAN_SLUGS[$type] ?? null;

        return $slug === null ? null : Plan::where('slug', $slug)->value('id');
    }

    /**
     * Se registró solo y el staff todavía no lo revisó. Mientras tanto no puede
     * invitar hoteleros: el mail sale con nuestro dominio y a nombre de una
     * agencia que nadie conoce.
     */
    public function needsVerification(): bool
    {
        return $this->origin === self::ORIGIN_SIGNUP && $this->verified_at === null;
    }

    /**
     * Logo listo para usar donde no se puede servir el original: Cloudinary lo
     * convierte por URL (PNG, alto fijo, fondo transparente conservado). Gmail
     * y Outlook no muestran SVG, por eso el mail nunca usa `logo_url` crudo.
     * El alto es el doble del que se muestra, para pantallas retina.
     */
    public function logoUrl(int $height = 96): ?string
    {
        if (! $this->logo_url) {
            return null;
        }

        return preg_replace(
            '#/image/upload/#',
            "/image/upload/f_png,h_{$height},c_fit,q_auto/",
            $this->logo_url,
            1
        );
    }

    /** Iniciales para el monograma cuando no hay logo: "Cámara Hotelera de Salta" → "CH". */
    public function initials(): string
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', trim((string) $this->name)) ?: [],
            fn (string $w) => mb_strlen($w) > 2,
        ));

        return mb_strtoupper(implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 2)))) ?: '·';
    }

    /**
     * Marca del client para la doble marca (mail, PMS, portal). Un solo lugar,
     * así las tres superficies muestran lo mismo.
     *
     * @return array{name: string, logo_url: ?string, initials: string}
     */
    public function brand(): array
    {
        return [
            'name' => $this->name,
            'logo_url' => $this->logoUrl(),
            'initials' => $this->initials(),
        ];
    }

    public function publishRequiresAgreement(): bool
    {
        return in_array($this->type, self::AGREEMENT_TO_PUBLISH_TYPES, true);
    }

    public function chargesMembershipDues(): bool
    {
        return in_array($this->type, self::MEMBERSHIP_DUES_TYPES, true);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

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
                'invite_subject' => ':client te invita a adherir',
                'pitch' => ':client te invita a sumar :accommodation a su padrón de alojamientos.',
            ],
            self::TYPE_CHAMBER => [
                'agreed' => 'Socio',
                'not_agreed' => 'No socio',
                'action' => 'Invitar a asociarse',
                'invite_subject' => ':client te invita a asociarte',
                'pitch' => ':client te invita a sumar :accommodation como socio de la cámara.',
            ],
            default => [
                'agreed' => 'Con convenio',
                'not_agreed' => 'Sin convenio',
                'action' => 'Invitar a convenio',
                'invite_subject' => ':client te propone un convenio',
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
