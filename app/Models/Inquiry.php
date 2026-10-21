<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Consulta de un viajero sobre un alojamiento o una experiencia
 * (docs/experiences-plan.md). `inquirable_type` guarda la clase completa.
 *
 * Tenencia: el hotelero ve las de sus alojamientos; un client, las de los
 * alojamientos de su padrón y las de sus experiencias; el staff, todas.
 */
class Inquiry extends Model
{
    use HasUuids;

    public const STATUSES = ['new', 'answered'];

    /**
     * Por dónde entró: formulario web legacy (`v1`), un portal (`client/v1`) o el
     * asistente del alojamiento (`/mcp/accommodation`).
     */
    public const SOURCES = ['web', 'portal', 'agent'];

    public const SOURCE_AGENT = 'agent';

    /** Alias públicos de `inquirable_type`. */
    public const TYPES = [
        'accommodation' => Accommodation::class,
        'experience' => Experience::class,
    ];

    protected $casts = [
        'inquirable_id' => 'integer',
        'client_id' => 'integer',
        'adults' => 'integer',
        'childrens' => 'integer',
    ];

    /** @return MorphTo<Model, $this> */
    public function inquirable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Canal por el que entró la consulta. `null` = origen sin atribuir
     * (histórico previo al campo, o formulario que no lo resolvió).
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Portal (client) por el que entró. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** `accommodation` | `experience` | null. */
    public function typeAlias(): ?string
    {
        $alias = array_search($this->inquirable_type, self::TYPES, true);

        return $alias === false ? null : $alias;
    }

    /** @param  Builder<Inquiry>  $query */
    public function scopeForAccommodation(Builder $query, int $accommodationId): void
    {
        $query->where('inquirable_type', Accommodation::class)->where('inquirable_id', $accommodationId);
    }

    /** @param  Builder<Inquiry>  $query */
    public function scopeForExperience(Builder $query, int $experienceId): void
    {
        $query->where('inquirable_type', Experience::class)->where('inquirable_id', $experienceId);
    }

    /** @param  Builder<Inquiry>  $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isPlatform()) {
            return;
        }

        $query->where(function (Builder $q) use ($user) {
            $q->where(fn (Builder $a) => $a
                ->where('inquiries.inquirable_type', Accommodation::class)
                ->whereIn('inquiries.inquirable_id', Accommodation::visibleTo($user)->select('accommodations.id'))
                // Lo que entra por el asistente del alojamiento (su WhatsApp, su
                // web) es del hotelero: el client que lo tiene en el padrón no lo ve.
                ->when($user->isClient(), fn (Builder $c) => $c->where('inquiries.source', '!=', self::SOURCE_AGENT)));

            if ($user->isClient() && $user->client_id !== null) {
                $q->orWhere(fn (Builder $e) => $e
                    ->where('inquiries.inquirable_type', Experience::class)
                    ->whereIn('inquiries.inquirable_id', Experience::query()
                        ->where('client_id', $user->client_id)
                        ->select('experiences.id')));
            }
        });
    }
}
