<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Models\Service;
use App\Repositories\Contracts\ServiceInterface;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Resources\V1\ServiceResource;
use App\Http\Requests\SearchServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Requests\DestroyServiceRequest;
use Illuminate\Routing\Controller as BaseController;

class ServiceController extends BaseController
{

    protected ServiceInterface $serviceInterface;

    public function __construct(ServiceInterface $serviceInterface)
    {
        $this->serviceInterface = $serviceInterface;
    }

    public function index(SearchServiceRequest $request)
    {
        $services = $this->serviceInterface->search($request);
        return response()->json($services, 200);
    }

    public function show(Service $service)
    {
        return new ServiceResource($service);
    }

    public function update(UpdateServiceRequest $request, $id)
    {
        $service = $this->serviceInterface->update($id, $request);
        return response()->json($service, 200);
    }

    public function store(StoreServiceRequest $request)
    {
        $service = $this->serviceInterface->store($request);
        return response()->json($service, 200);
    }

    public function destroy(DestroyServiceRequest $request)
    {
        $service = $this->serviceInterface->destroy($request);
        return response()->json([
            'message' => 'Service deleted successfully',
            'data' => $service
        ], 200);
    }
}