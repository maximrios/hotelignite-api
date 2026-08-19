<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyServiceRequest;
use App\Http\Requests\SearchServiceRequest;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\Service;
use Illuminate\Http\Request;

interface ServiceInterface
{
    public function all(Request $request);

    public function find($id);

    public function search(SearchServiceRequest $request);

    /** @param  Service|int|string  $service */
    public function update($service, UpdateServiceRequest $request);

    public function store(StoreServiceRequest $request);

    /** Baja legacy de `/api/v1/services`, con el id en el cuerpo. */
    public function destroy(DestroyServiceRequest $request);

    /** Baja de `admin/v1`, con el id en la ruta. Aborta con 409 si está en uso. */
    public function remove(Service $service): void;
}
