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

    protected $fillable = [
        'name',
        'type',
        'channel_id',
        'email',
        'phone',
        'active',
        'rate_limit_per_minute',
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
            ->withPivot(['status', 'verified_at', 'verified_by_user_id', 'invitation_id'])
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

    /**
     * Rate limit (requests/minuto) del client. Usa el tier propio si está
     * seteado; si no, el default de config (`api.client_default_rpm`).
     */
    public function rateLimit(): int
    {
        return $this->rate_limit_per_minute ?: (int) config('api.client_default_rpm', 60);
    }
}
