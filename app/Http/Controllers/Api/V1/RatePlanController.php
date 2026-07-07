<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\DestroyRatePlanRequest;
use App\Http\Requests\SearchRatePlanRequest;
use App\Http\Requests\StoreRatePlanRequest;
use App\Http\Requests\UpdateRatePlanRequest;
use App\Repositories\Contracts\RatePlanInterface;
use Illuminate\Routing\Controller as BaseController;

class RatePlanController extends BaseController
{
    protected RatePlanInterface $ratePlanInterface;

    public function __construct(RatePlanInterface $ratePlanInterface)
    {
        $this->ratePlanInterface = $ratePlanInterface;
    }

    public function index(SearchRatePlanRequest $request)
    {
        return response()->json($this->ratePlanInterface->search($request), 200);
    }

    public function show($id)
    {
        return response()->json($this->ratePlanInterface->find($id), 200);
    }

    public function store(StoreRatePlanRequest $request)
    {
        return response()->json($this->ratePlanInterface->store($request), 201);
    }

    public function update(UpdateRatePlanRequest $request, $id)
    {
        return response()->json($this->ratePlanInterface->update($id, $request), 200);
    }

    public function destroy(DestroyRatePlanRequest $request)
    {
        $ratePlan = $this->ratePlanInterface->destroy($request);
        return response()->json([
            'message' => 'Rate plan deleted successfully',
            'data' => $ratePlan,
        ], 200);
    }
}
