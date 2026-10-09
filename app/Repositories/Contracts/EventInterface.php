<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\Admin\SearchEventRequest;
use App\Http\Resources\Admin\EventResource;
use App\Http\Resources\Admin\EventResourceCollection;
use App\Models\Accommodation;
use App\Models\Event;
use Carbon\CarbonImmutable;

interface EventInterface
{
    /** `$ownerClientId`: sólo los cargados por ese client (el listado del portal). */
    public function search(SearchEventRequest $request, ?int $ownerClientId = null): EventResourceCollection;

    public function show(Event $event): EventResource;

    /**
     * @param  array<string, mixed>  $data  ya validado
     * @param  int|null  $clientId  quién lo carga; null = staff
     */
    public function store(array $data, ?int $clientId = null): EventResource;

    /** @param  array<string, mixed>  $data  ya validado */
    public function update(Event $event, array $data): EventResource;

    public function remove(Event $event): void;

    /**
     * Eventos que ocurren entre `$from` y `$to` a menos de `$radiusM` del
     * alojamiento. Excluye cancelados y deshabilitados.
     *
     * @return array{accommodation_id: int, has_location: bool, from: string, to: string, radius_m: int, items: list<EventResource>}
     */
    public function nearby(Accommodation $accommodation, CarbonImmutable $from, CarbonImmutable $to, int $radiusM, int $limit): array;
}
