<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Accommodation extends Model
{
    protected $fillable = [
        'account_id',
        'plan_id',
        'type_id',
        'name',
        'legal_name',
        'stars',
        'slug',
        'email',
        'phone',
        'email_reservations',
        'phone_reservations',
        'web',
        'address',
        'address2',
        'landmark',
        'postal_code',
        'city_id',
        'state_id',
        'country_id',
        'latitude',
        'longitude',
        'file_number',
        'tax_identification',
        'currency_id',
        'language_id',
        'timezone',
        'channel_code',
        'bank_data',
        'comment',
        'logo',
        'enabled',
        'active',
        'test',
        'expiration',
    ];

    /**
     * `location` es una columna generada desde `latitude`/`longitude` (no se
     * escribe) y sale como WKB hexadecimal: no sirve en ningún JSON.
     */
    protected $hidden = [
        'location',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'active' => 'boolean',
        'test' => 'boolean',
        'stars' => 'integer',
    ];

    /**
     * Genera un slug único a partir del nombre cuando falta. Se dispara en cada
     * save (create y update): si el accommodation ya tiene slug no se toca —
     * los slugs son estables para no romper los links de los portales.
     */
    /**
     * Columnas legacy `NOT NULL` con default "sin dato" (`'0'` / `0`), con ese
     * default. Los FormRequests las validan `nullable` y el middleware
     * `ConvertEmptyStringsToNull` convierte el `""` de un input vacío en `null`:
     * sin esto, borrar el país o el teléfono desde el PMS termina en un 500
     * (`Not null violation`). Se guarda el default, que es como el legacy
     * representa el vacío.
     */
    private const LEGACY_NOT_NULL_DEFAULTS = [
        'tax_identification' => '0',
        'logo' => '0',
        'account_id' => 0,
        'name' => '0',
        'address' => '0',
        'email' => '0',
        'phone' => '0',
        'email_reservations' => '0',
        'phone_reservations' => '0',
        'web' => '0',
        'latitude' => '0',
        'longitude' => '0',
        'postal_code' => '0',
        'city_id' => 0,
        'state_id' => 0,
        'country_id' => '0',
        'type_id' => 0,
        'language_id' => '0',
        'currency_id' => '0',
        'token' => '0',
        'enabled' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (Accommodation $accommodation) {
            $attributes = $accommodation->getAttributes();
            foreach (self::LEGACY_NOT_NULL_DEFAULTS as $column => $default) {
                if (array_key_exists($column, $attributes) && $attributes[$column] === null) {
                    $accommodation->setAttribute($column, $default);
                }
            }

            if (empty($accommodation->slug) && ! empty($accommodation->name)) {
                $accommodation->slug = static::generateUniqueSlug($accommodation->name, $accommodation->id);
            }
        });
    }

    /**
     * Slug único basado en el nombre; agrega sufijo -2, -3, … si colisiona.
     */
    protected static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'alojamiento';
        $slug = $base;
        $suffix = 2;

        while (
            static::where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'accommodation_client')
            ->withPivot(['status', 'verified_at', 'verified_by_user_id', 'invitation_id'])
            ->withTimestamps();
    }

    /**
     * Canales a los que este alojamiento está conectado. El pivote lleva las
     * condiciones propias del hotel (comisión negociada, código externo) —
     * `channels.commission_rate` es sólo el default del catálogo.
     */
    public function channels()
    {
        return $this->belongsToMany(Channel::class, AccommodationChannel::class, 'accommodation_id', 'channel_id')
            ->withPivot(['enabled', 'commission_rate', 'external_code'])
            ->withTimestamps();
    }

    public function invitations()
    {
        return $this->hasMany(Invitation::class);
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Limita la consulta a los accommodations visibles para el usuario dado.
     * platform: todos · account: los de su cuenta · client: los relacionados.
     * Fail-closed: un user sin cuenta/cliente no ve nada.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isPlatform()) {
            return $query;
        }

        if ($user->isClient()) {
            // Solo los vínculos `active` cuentan: el pivote nace `pending` y el
            // client no ve el alojamiento en su padrón hasta que el staff lo
            // aprueba (§8 de la skill de invitaciones).
            return $query->whereHas('clients', fn ($q) => $q->where('clients.id', $user->client_id)
                ->where('accommodation_client.status', 'active'));
        }

        // account: si tiene un acotamiento explícito (pivote accommodation_user),
        // ve solo esos; si no, toda su cuenta —conjunto dinámico que crece con
        // cada alta. Espejo de la rama de client de arriba.
        $scopedIds = $user->accommodations()->pluck('accommodations.id');

        if ($scopedIds->isNotEmpty()) {
            return $query->whereIn('id', $scopedIds);
        }

        // Sin cuenta no hay nada visible. Sin este corte, where('account_id', null)
        // compila a "account_id is null" y expondría los alojamientos huérfanos.
        if ($user->account_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('account_id', $user->account_id);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * ¿El plan de este alojamiento incluye la feature dada?
     * Fuente de verdad de los entitlements (plan por alojamiento, no por cuenta).
     */
    public function hasFeature(string $slug): bool
    {
        return (bool) $this->plan?->hasFeature($slug);
    }

    /**
     * Límite de una feature para este alojamiento (null = ilimitado o no incluida).
     */
    public function featureLimit(string $slug): ?int
    {
        return $this->plan?->featureLimit($slug);
    }

    /**
     * Reservas online habilitadas = el plan incluye el motor de reservas.
     */
    public function allowsOnlineBookings(): bool
    {
        return $this->hasFeature('booking_engine');
    }

    public function roomTypes()
    {
        return $this->hasMany(RoomType::class);
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('order');
    }

    public function descriptions()
    {
        return $this->hasMany(AccommodationDescription::class);
    }

    public function type()
    {
        return $this->belongsTo(AccommodationType::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, AccommodationService::class, 'accommodation_id', 'service_id');
    }

    public function policies()
    {
        return $this->belongsToMany(Policy::class, AccommodationPolicyOld::class, 'accommodation_id', 'policy_id')
            ->withPivot('language_id', 'description');
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
