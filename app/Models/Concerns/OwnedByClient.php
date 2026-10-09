<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contenido del catálogo turístico que puede cargar un client B2B además del
 * staff (docs/events-plan.md). `client_id` NULL = cargado por el staff.
 *
 * `client_id` no va en `$fillable`: lo fija el repositorio desde el token, nunca
 * el cuerpo de la request.
 */
trait OwnedByClient
{
    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** El staff edita todo; un client, sólo lo que cargó él. */
    public function isEditableBy(User $user): bool
    {
        if ($user->isPlatform()) {
            return true;
        }

        return $user->isClient()
            && $user->client_id !== null
            && (int) $this->getAttribute('client_id') === (int) $user->client_id;
    }

    public function scopeOwnedByClient(Builder $query, int $clientId): void
    {
        $query->where($this->qualifyColumn('client_id'), $clientId);
    }
}
