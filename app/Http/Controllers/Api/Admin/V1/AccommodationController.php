<?php

namespace App\Http\Controllers\Api\Admin\V1;

use Illuminate\Http\Request;
use App\Models\Accommodation;
use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Requests\UpdateAccommodationRequest;
use App\Http\Requests\Admin\PatchAccommodationRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller as BaseController;
use App\Http\Resources\Admin\AccommodationResource;
use App\Http\Resources\Admin\AccommodationResourceCollection;

class AccommodationController extends BaseController
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', Accommodation::class);

        $limit = $request->integer('limit', 15);

        $accommodations = Accommodation::with(['city', 'state', 'type'])
            ->visibleTo($user)
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->has('enabled'), fn ($q) => $q->where('enabled', $request->boolean('enabled')))
            // Solo platform puede filtrar por una cuenta arbitraria; el resto queda scopeado por visibleTo.
            ->when($user->isPlatform() && $request->account_id, fn ($q, $accountId) => $q->where('account_id', $accountId))
            ->paginate($limit);

        return new AccommodationResourceCollection($accommodations);
    }

    public function show(Accommodation $accommodation)
    {
        $this->authorize('view', $accommodation);

        // Las mismas relaciones que carga el index: sin esto el Resource omite
        // `city`, `state` y `type` (son `whenLoaded`), y el detalle devuelve
        // menos campos que el listado para el mismo recurso.
        $accommodation->load(['city', 'state', 'type']);

        return new AccommodationResource($accommodation);
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
