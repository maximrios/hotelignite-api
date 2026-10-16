<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\PatchAccommodationRequest;
use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Requests\UpdateAccommodationRequest;
use App\Http\Resources\Admin\AccommodationResource;
use App\Http\Resources\Admin\AccommodationResourceCollection;
use App\Models\Accommodation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class AccommodationController extends BaseController
{
    use AuthorizesRequests;

    private const MAX_LIMIT = 100;

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', Accommodation::class);

        // Tope duro: sin él, `?limit=` pedía la tabla entera de una vez.
        $limit = min(max($request->integer('limit', 15), 1), self::MAX_LIMIT);

        $accommodations = Accommodation::with(['city', 'state', 'type'])
            ->with($this->clientPivot($user))
            ->visibleTo($user)
            ->when($request->search, fn ($q, $search) => $q->whereLike('name', "%{$search}%"))
            ->when($request->has('enabled'), fn ($q) => $q->where('enabled', $request->boolean('enabled')))
            ->when($request->integer('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($request->integer('type_id'), fn ($q, $typeId) => $q->where('type_id', $typeId))
            // Estado derivado del padrón: borrador = sin dueño todavía.
            ->when($request->owner === 'draft', fn ($q) => $q->whereNull('account_id'))
            ->when($request->owner === 'claimed', fn ($q) => $q->whereNotNull('account_id'))
            // Solo platform puede filtrar por una cuenta arbitraria; el resto queda scopeado por visibleTo.
            ->when($user->isPlatform() && $request->account_id, fn ($q, $accountId) => $q->where('account_id', $accountId))
            ->paginate($limit);

        return new AccommodationResourceCollection($accommodations);
    }

    public function show(Request $request, Accommodation $accommodation)
    {
        $this->authorize('view', $accommodation);

        // Las mismas relaciones que carga el index: sin esto el Resource omite
        // `city`, `state` y `type` (son `whenLoaded`), y el detalle devuelve
        // menos campos que el listado para el mismo recurso.
        $accommodation->load(['city', 'state', 'type'])->load($this->clientPivot($request->user()));

        return new AccommodationResource($accommodation);
    }

    /**
     * Para un client, el pivote con él (sólo el suyo): el Resource lo usa para
     * decidir si le muestra el contacto del alojamiento.
     *
     * @return array<string, \Closure>
     */
    private function clientPivot(User $user): array
    {
        return $user->isClient()
            ? ['clients' => fn ($q) => $q->where('clients.id', $user->client_id)]
            : [];
    }

    public function store(StoreAccommodationRequest $request)
    {
        $this->authorize('create', Accommodation::class);

        $data = $request->validated();
        // Un user de cuenta solo puede crear en su propia cuenta; platform puede especificarla.
        if (! $request->user()->isPlatform()) {
            $data['account_id'] = $request->user()->account_id;
        }

        $accommodation = Accommodation::create($data);

        return new AccommodationResource($accommodation);
    }

    public function update(UpdateAccommodationRequest $request, int $id)
    {
        $accommodation = Accommodation::findOrFail($id);
        $this->authorize('update', $accommodation);

        $data = $request->validated();
        // Evita que se reasigne el accommodation a otra cuenta.
        if (! $request->user()->isPlatform()) {
            unset($data['account_id']);
        }

        $accommodation->update($data);

        return new AccommodationResource($accommodation->fresh(['city', 'state', 'type']));
    }

    public function partialUpdate(PatchAccommodationRequest $request, int $id)
    {
        $accommodation = Accommodation::findOrFail($id);
        $this->authorize('update', $accommodation);

        $data = $request->validated();
        if (! $request->user()->isPlatform()) {
            unset($data['account_id']);
        }

        $accommodation->update($data);

        return new AccommodationResource($accommodation->fresh(['city', 'state', 'type']));
    }

    public function destroy(Request $request, int $id)
    {
        $accommodation = Accommodation::findOrFail($id);
        $this->authorize('delete', $accommodation);

        $accommodation->delete();

        return response()->json(['message' => 'Accommodation deleted successfully'], 200);
    }
}
