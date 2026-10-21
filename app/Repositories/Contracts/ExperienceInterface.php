<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\Admin\SearchExperienceRequest;
use App\Http\Resources\Admin\ExperienceResource;
use App\Http\Resources\Admin\ExperienceResourceCollection;
use App\Models\Experience;

interface ExperienceInterface
{
    /** `$ownerClientId`: sólo las de ese client (el listado del portal). */
    public function search(SearchExperienceRequest $request, ?int $ownerClientId = null): ExperienceResourceCollection;

    public function show(Experience $experience): ExperienceResource;

    /**
     * @param  array<string, mixed>  $data  ya validado; `client_id` sólo si lo manda el staff
     * @param  int|null  $clientId  el client del token; pisa a `$data['client_id']`
     */
    public function store(array $data, ?int $clientId = null): ExperienceResource;

    /** @param  array<string, mixed>  $data  ya validado */
    public function update(Experience $experience, array $data): ExperienceResource;

    public function remove(Experience $experience): void;
}
