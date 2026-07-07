<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreAccommodationRatePolicyRequest;
use App\Http\Requests\UpdateAccommodationRatePolicyRequest;
use App\Http\Requests\DestroyAccommodationRatePolicyRequest;
use App\Http\Requests\SearchAccommodationRatePolicyRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\AccommodationRatePolicyInterface;

class AccommodationRatePolicyController extends BaseController
{
    protected AccommodationRatePolicyInterface $repo;

    public function __construct(AccommodationRatePolicyInterface $repo)
    {
        $this->repo = $repo;
    }

    public function index(SearchAccommodationRatePolicyRequest $request)
    {
        return response()->json($this->repo->search($request), 200);
    }

    public function show(int $id)
    {
        return response()->json($this->repo->find($id), 200);
    }

    public function store(StoreAccommodationRatePolicyRequest $request)
    {
        $ratePolicy = $this->repo->store($request);
        return response()->json([
            'message' => 'Accommodation rate policy created successfully',
            'data'    => $ratePolicy,
        ], 201);
    }

    public function update(UpdateAccommodationRatePolicyRequest $request, int $id)
    {
        $ratePolicy = $this->repo->update($id, $request);
        return response()->json([
            'message' => 'Accommodation rate policy updated successfully',
            'data'    => $ratePolicy,
        ], 200);
    }

    public function destroy(DestroyAccommodationRatePolicyRequest $request)
    {
        $ratePolicy = $this->repo->destroy($request);
        return response()->json([
            'message' => 'Accommodation rate policy deleted successfully',
            'data'    => $ratePolicy,
        ], 200);
    }
}
