<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\AccommodationService;
use App\Http\Requests\StoreAccommodationServiceRequest;
use App\Http\Resources\V1\AccommodationServiceResource;
use App\Http\Requests\SearchAccommodationServiceRequest;
use App\Http\Requests\UpdateAccommodationServiceRequest;
use App\Http\Requests\DestroyAccommodationServiceRequest;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\AccommodationServiceInterface;

class AccommodationServiceController extends BaseController
{
    protected AccommodationServiceInterface $accommodationServiceInterface;

    public function __construct(AccommodationServiceInterface $accommodationServiceInterface)
    {
        $this->accommodationServiceInterface = $accommodationServiceInterface;
    }

    public function index(SearchAccommodationServiceRequest $request)
    {
        $accommodationServices = $this->accommodationServiceInterface->search($request);
        return response()->json($accommodationServices, 200);
    }

    public function show($id)
    {
        $accommodationService = AccommodationService::find($id);
        return new AccommodationServiceResource($accommodationService);
    }

    public function update(UpdateAccommodationServiceRequest $request, $id)
    {
        $services = $this->accommodationServiceInterface->update($id, $request);
        return response()->json([
            'message' => 'Services synchronized successfully',
            'data' => $services
        ], 200);
    }

    public function store(StoreAccommodationServiceRequest $request)
    {
        $services = $this->accommodationServiceInterface->store($request);
        return response()->json([
            'message' => 'Services attached successfully',
            'data' => $services
        ], 201);
    }

    public function destroy(DestroyAccommodationServiceRequest $request)
    {
        $accommodationService = $this->accommodationServiceInterface->destroy($request);
        return response()->json([
            'message' => 'AccommodationService deleted successfully',
            'data' => $accommodationService
        ], 200);
    }
}
