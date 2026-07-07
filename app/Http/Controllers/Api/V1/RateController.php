<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\DestroyRateRequest;
use App\Http\Requests\SearchRateRequest;
use App\Http\Requests\StoreRateRequest;
use App\Http\Requests\UpdateRateRequest;
use App\Repositories\Contracts\RateInterface;
use Illuminate\Routing\Controller as BaseController;

class RateController extends BaseController
{
    protected RateInterface $rateInterface;

    public function __construct(RateInterface $rateInterface)
    {
        $this->rateInterface = $rateInterface;
    }

    public function index(SearchRateRequest $request)
    {
        return response()->json($this->rateInterface->search($request), 200);
    }

    public function show($id)
    {
        return response()->json($this->rateInterface->find($id), 200);
    }

    public function store(StoreRateRequest $request)
    {
        return response()->json($this->rateInterface->store($request), 201);
    }

    public function update(UpdateRateRequest $request, $id)
    {
        return response()->json($this->rateInterface->update($id, $request), 200);
    }

    public function destroy(DestroyRateRequest $request)
    {
        $rate = $this->rateInterface->destroy($request);
        return response()->json([
            'message' => 'Rate deleted successfully',
            'data' => $rate,
        ], 200);
    }
}
