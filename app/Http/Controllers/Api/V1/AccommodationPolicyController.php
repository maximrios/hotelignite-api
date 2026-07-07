<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreAccommodationPolicyRequest;
use App\Http\Requests\UpdateAccommodationPolicyRequest;
use App\Http\Requests\DestroyAccommodationPolicyRequest;
use App\Http\Requests\SearchAccommodationPolicyRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\AccommodationPolicyInterface;

class AccommodationPolicyController extends BaseController
{
    protected AccommodationPolicyInterface $repo;

    public function __construct(AccommodationPolicyInterface $repo)
    {
        $this->repo = $repo;
    }

    public function index(SearchAccommodationPolicyRequest $request)
    {
        return response()->json($this->repo->search($request), 200);
    }

    public function show(int $id)
    {
        return response()->json($this->repo->find($id), 200);
    }

    public function store(StoreAccommodationPolicyRequest $request)
    {
        $policy = $this->repo->store($request);
        return response()->json([
            'message' => 'Accommodation policy created successfully',
            'data'    => $policy,
        ], 201);
    }

    public function update(UpdateAccommodationPolicyRequest $request, int $id)
    {
        $policy = $this->repo->update($id, $request);
        return response()->json([
            'message' => 'Accommodation policy updated successfully',
            'data'    => $policy,
        ], 200);
    }

    public function destroy(DestroyAccommodationPolicyRequest $request)
    {
        $policy = $this->repo->destroy($request);
        return response()->json([
            'message' => 'Accommodation policy deleted successfully',
            'data'    => $policy,
        ], 200);
    }
}
