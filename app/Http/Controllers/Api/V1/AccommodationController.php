<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\Accommodation;
use App\Http\Requests\GetAccommodationRequest;
use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Resources\V1\AccommodationResource;
use App\Http\Requests\SearchAccommodationRequest;
use App\Http\Requests\UpdateAccommodationRequest;
use App\Http\Requests\DestroyAccommodationRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\AccommodationInterface;
use App\Http\Resources\V1\ServiceResourceCollection;
use App\Http\Resources\V1\AccommodationPolicyOldResourceCollection;
use App\Models\AccommodationPolicyOld;

class AccommodationController extends BaseController
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    protected AccommodationInterface $accommodationInterface;

    public function __construct(AccommodationInterface $accommodationInterface)
    {
        $this->accommodationInterface = $accommodationInterface;
    }

    public function index(SearchAccommodationRequest $request)
    {
        $accommodations = $this->accommodationInterface->search($request);
        return response()->json($accommodations, 200);
    }

    //public function show(GetAccommodationRequest $request)
    public function show($slug)
    {
        $accommodation = Accommodation::with('plan.features')
            ->where('slug', $slug)
            ->first();
        return new AccommodationResource($accommodation);
    }

    public function update(UpdateAccommodationRequest $request, $id)
    {
        $accommodation = Accommodation::findOrFail($id);
        $this->authorize('update', $accommodation);

        // Impide reasignar el accommodation a otra cuenta (el repo usa $request->all()).
        if (! $request->user()->isPlatform()) {
            $request->merge(['account_id' => $accommodation->account_id]);
        }

        $accommodation = $this->accommodationInterface->update($id, $request);
        return response()->json($accommodation, 200);
    }

    public function store(StoreAccommodationRequest $request)
    {
        $this->authorize('create', Accommodation::class);

        // Un user de cuenta solo puede crear en su propia cuenta.
        if (! $request->user()->isPlatform()) {
            $request->merge(['account_id' => $request->user()->account_id]);
        }

        $accommodation = $this->accommodationInterface->store($request);
        return response()->json($accommodation, 200);
    }

    public function destroy(DestroyAccommodationRequest $request)
    {
        $accommodation = Accommodation::findOrFail($request->accommodation_id);
        $this->authorize('delete', $accommodation);

        $accommodation = $this->accommodationInterface->destroy($request);
        return response()->json([
            'message' => 'Accommodation deleted successfully',
            'data' => $accommodation
        ], 200);
    }

    public function services($id)
    {
        $accommodation = Accommodation::find($id);
        
        if (!$accommodation) {
            return response()->json([
                'message' => 'Accommodation not found'
            ], 404);
        }

        $services = $accommodation->services()->get();
        return new ServiceResourceCollection($services);
    }

    public function policies($id)
    {
        $accommodation = Accommodation::find($id);
        
        if (!$accommodation) {
            return response()->json([
                'message' => 'Accommodation not found'
            ], 404);
        }

        $policies = AccommodationPolicyOld::where('accommodation_id', $id)
            ->with(['policy', 'accommodation'])
            ->get();

        return new AccommodationPolicyOldResourceCollection($policies);
    }
}