<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\AttachClientAccommodationsRequest;
use App\Http\Requests\Admin\StoreClientRequest;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Http\Resources\Admin\ClientResource;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Gestión de clients B2B (solo plataforma). Alta/edición, tier y relación con
 * los accommodations que cada client puede leer. Las keys se manejan en
 * ClientApiKeyController. Ver docs/api-clients-plan.md (F4).
 */
class ClientController extends BaseController
{
    public function index(Request $request)
    {
        $clients = Client::withCount('accommodations')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->query('search')}%"))
            ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
            ->orderByDesc('id')
            ->paginate($request->integer('limit', 15));

        return ClientResource::collection($clients);
    }

    public function store(StoreClientRequest $request)
    {
        $client = Client::create($request->validated());

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function show(Client $client)
    {
        $client->loadCount('accommodations')->load(['accommodations', 'apiKeys']);

        return new ClientResource($client);
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        $client->update($request->validated());

        return new ClientResource($client->fresh());
    }

    public function attachAccommodations(AttachClientAccommodationsRequest $request, Client $client)
    {
        $client->accommodations()->syncWithoutDetaching($request->validated()['accommodation_ids']);

        return new ClientResource($client->load('accommodations'));
    }

    public function detachAccommodation(Client $client, int $accommodationId)
    {
        $client->accommodations()->detach($accommodationId);

        return new ClientResource($client->load('accommodations'));
    }
}
