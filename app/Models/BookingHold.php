<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Habitaciones apartadas mientras el huésped completa el stepper. Fechas y totales
 * quedan acá: la URL del checkout sólo lleva el token.
 */
class BookingHold extends Model
{
    use HasUuids;

    public const TTL_MINUTES = 15;

    protected $fillable = [
        'token_hash',
        'accommodation_id',
        'checkin_date',
        'checkout_date',
        'adults',
        'children',
        'total_amount',
        'currency',
        'expires_at',
        'submitted_at',
        'client_ip',
        'attribution',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'attribution' => 'array',
        'checkin_date' => 'date',
        'checkout_date' => 'date',
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Sin confirmar y sin vencer: la habitación sigue apartada. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('submitted_at')->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->submitted_at === null && $this->expires_at->isPast();
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(BookingHoldRoom::class)->orderBy('id');
    }
}
