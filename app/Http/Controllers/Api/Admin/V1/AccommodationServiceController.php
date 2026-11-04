<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Models\Accommodation;
use App\Models\Service;
use App\Http\Resources\V1\ServiceResource;
use App\Http\Requests\UpdateAccommodationServiceRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller as BaseController;

class AccommodationServiceController extends BaseController
{
    use AuthorizesRequests;

    public function index(Accommodation $accommodation)
    {
        $this->authorize('view', $accommodation);

        return ServiceResource::collection($accommodation->services);
    }

    public function store(UpdateAccommodationServiceRequest $request, Accommodation $accommodation)
    {
        $this->authorize('update', $accommodation);

        $accommodation->services()->syncWithoutDetaching($request->validated('service_ids'));
        $accommodation->load('services');

        return ServiceResource::collection($accommodation->services);
    }

    public function update(UpdateAccommodationServiceRequest $request, Accommodation $accommodation)
    {
        $this->authorize('update', $accommodation);

        $accommodation->services()->sync($request->validated('service_ids'));
        $accommodation->load('services');

        return ServiceResource::collection($accommodation->services);
    }

    public function destroy(Accommodation $accommodation, Service $service)
    {
        $this->authorize('update', $accommodation);

        $accommodation->services()->detach($service->id);

        return response()->json(['message' => 'Service detached successfully'], 200);
    }
}
