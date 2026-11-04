<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un agente IA activado en un alojamiento. Ver App\Agents\AgentCatalog y
 * docs/ai-agents-plan.md.
 *
 * @property int $id
 * @property int $accommodation_id
 * @property string $agent
 * @property string $status
 * @property int|null $api_key_id
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $widget
 * @property string|null $last_error
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $activated_at
 */
class AccommodationAgent extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** Colmexa rechazó o no respondió la última sincronización. */
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'accommodation_id',
        'agent',
        'status',
        'api_key_id',
        'settings',
        'widget',
        'last_error',
        'last_synced_at',
        'activated_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'widget' => 'array',
        'last_synced_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    /** @return BelongsTo<Accommodation, $this> */
    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    /** @return BelongsTo<AccommodationApiKey, $this> */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(AccommodationApiKey::class, 'api_key_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
