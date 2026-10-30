<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Client;
use App\Models\User;

interface PublicationInterface
{
    /**
     * Habilita o quita de la web del client un lote de asociados del mismo
     * tipo. Escribe `verified_at` / `verified_by_user_id` en el pivote. Lo que no
     * cumple la regla se saltea con un motivo, no aborta el lote.
     *
     * @param  list<int>  $ids
     * @return array{updated: list<int>, skipped: list<array{entity_id: int, reason: string}>}
     */
    public function setPublished(Client $client, User $by, string $type, array $ids, bool $published): array;
}
