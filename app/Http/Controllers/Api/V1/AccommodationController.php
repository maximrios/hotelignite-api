<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\SearchAccommodationRequest;
use Illuminate\Http\Request;
use App\Models\Accommodation;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\StoreAccommodationRequest;
use App\Http\Resources\V1\AccommodationResource;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\AccommodationInterface;
use App\Http\Resources\V1\AccommodationResourceCollection;

class AccommodationController extends BaseController
{

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

    public function show(Request $request)
    {
        return response()->json($this->accommodationInterface->find($request->id), 200);
    }

    public function store(StoreAccommodationRequest $request)
    {
        $accommodation = Accommodation::create( $request->all() );
        return new AccommodationResource( $accommodation );
    }
}