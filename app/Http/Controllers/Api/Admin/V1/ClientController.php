<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\AttachClientAccommodationsRequest;
use App\Http\Requests\Admin\StoreClientRequest;
use App\Http\Requests\Admin\UpdateClientRequest;
use App\Http\Resources\Admin\ClientResource;
use App\Models\Accommodation;
use App\Models\Client;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

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
            ->when($request->filled('search'), fn ($q) => $q->whereLike('name', "%{$request->query('search')}%"))
            ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
            // Agencias que se registraron solas y esperan la verificación del staff.
            ->when($request->boolean('pending_verification'), fn ($q) => $q->where('origin', Client::ORIGIN_SIGNUP)->whereNull('verified_at'))
            ->orderByDesc('id')
            ->paginate($request->integer('limit', 15));

        return ClientResource::collection($clients);
    }

    public function store(StoreClientRequest $request)
    {
        $client = new Client($request->validated());
        $this->assertPlanOffered($client);
        $client->save();

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function show(Client $client)
    {
        $client->loadCount('accommodations')->load(['accommodations', 'apiKeys']);

        return new ClientResource($client);
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        $data = $request->validated();
        $verified = array_key_exists('verified', $data) ? (bool) $data['verified'] : null;
        unset($data['verified']);

        $client->fill($data);

        // Todo client tiene plan: sacarle el plan (o cambiarle el tipo sin plan)
        // lo deja en el free de su tipo, no en null.
        if ($client->plan_id === null) {
            $client->plan_id = Client::freePlanIdFor($client->type);
        }
        $this->assertPlanOffered($client);

        if ($verified !== null) {
            $client->forceFill([
                'verified_at' => $verified ? ($client->verified_at ?? now()) : null,
                'verified_by_user_id' => $verified ? $request->user()->id : null,
            ]);
        }

        $client->save();

        return new ClientResource($client->fresh());
    }

    /** Un plan de gobierno no se le asigna a una agencia, ni al revés. */
    private function assertPlanOffered(Client $client): void
    {
        $plan = $client->plan_id ? Plan::find($client->plan_id) : null;

        abort_if(
            $plan !== null && ! $plan->offeredTo($client->type),
            422,
            'Ese plan no se ofrece a este tipo de client.'
        );
    }

    public function attachAccommodations(AttachClientAccommodationsRequest $request, Client $client)
    {
        $client->accommodations()->syncWithoutDetaching($request->validated()['accommodation_ids']);

        return new ClientResource($client->load('accommodations'));
    }

    /**
     * Suma al padrón del client todos los alojamientos que matchean los mismos
     * filtros del listado del CRM (ciudad, tipo, dueño, búsqueda). Es como se le
     * arma a una agencia el padrón de una zona entera sin elegir uno por uno.
     *
     * Entran sin vínculo (`agreed_at` null): estar en el padrón no es tener
     * convenio. Los que ya estaban no se tocan, ni siquiera un rechazo previo.
     */
    public function attachAccommodationsByFilter(Request $request, Client $client)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'integer'],
            'type_id' => ['nullable', 'integer'],
            'owner' => ['nullable', 'in:draft,claimed'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $ids = Accommodation::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereLike('name', "%{$search}%"))
            ->when(array_key_exists('enabled', $filters) && $filters['enabled'] !== null, fn ($q) => $q->where('enabled', (bool) $filters['enabled']))
            ->when($filters['city_id'] ?? null, fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($filters['type_id'] ?? null, fn ($q, $typeId) => $q->where('type_id', $typeId))
            ->when(($filters['owner'] ?? null) === 'draft', fn ($q) => $q->whereNull('account_id'))
            ->when(($filters['owner'] ?? null) === 'claimed', fn ($q) => $q->whereNotNull('account_id'))
            ->whereNotIn('id', $client->accommodations()->select('accommodations.id'))
            ->pluck('id');

        $now = now();
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('accommodation_client')->insertOrIgnore($chunk->map(fn (int $id) => [
                'accommodation_id' => $id,
                'client_id' => $client->id,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());
        }

        return response()->json([
            'attached' => $ids->count(),
            'total' => $client->accommodations()->count(),
        ]);
    }

    public function detachAccommodation(Client $client, int $accommodationId)
    {
        $client->accommodations()->detach($accommodationId);

        return new ClientResource($client->load('accommodations'));
    }
}
