<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AccommodationPolicyOld;
use App\Http\Requests\StoreAccommodationPolicyOldRequest;
use App\Http\Resources\V1\AccommodationPolicyOldResource;
use App\Http\Requests\SearchAccommodationPolicyOldRequest;
use App\Http\Requests\UpdateAccommodationPolicyOldRequest;
use App\Http\Requests\DestroyAccommodationPolicyOldRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\AccommodationPolicyOldInterface;

class AccommodationPolicyOldController extends BaseController
{
    protected AccommodationPolicyOldInterface $accommodationPolicyOldInterface;

    public function __construct(AccommodationPolicyOldInterface $accommodationPolicyOldInterface)
    {
        $this->accommodationPolicyOldInterface = $accommodationPolicyOldInterface;
    }

    public function index(SearchAccommodationPolicyOldRequest $request)
    {
        $accommodationPolicies = $this->accommodationPolicyOldInterface->search($request);
        return response()->json($accommodationPolicies, 200);
    }

    public function show($id)
    {
        $accommodationPolicy = AccommodationPolicyOld::find($id);
        return new AccommodationPolicyOldResource($accommodationPolicy);
    }

    public function update(UpdateAccommodationPolicyOldRequest $request, $id)
    {
        $policies = $this->accommodationPolicyOldInterface->update($id, $request);
        return response()->json([
            'message' => 'Policies updated successfully',
            'data' => $policies,
        ], 200);
    }

    public function store(StoreAccommodationPolicyOldRequest $request)
    {
        $policies = $this->accommodationPolicyOldInterface->store($request);
        return response()->json([
            'message' => 'Policies created successfully',
            'data' => $policies,
        ], 201);
    }

    public function destroy(DestroyAccommodationPolicyOldRequest $request)
    {
        $accommodationPolicy = $this->accommodationPolicyOldInterface->destroy($request);
        return response()->json([
            'message' => 'AccommodationPolicyOld deleted successfully',
            'data' => $accommodationPolicy,
        ], 200);
    }
}
