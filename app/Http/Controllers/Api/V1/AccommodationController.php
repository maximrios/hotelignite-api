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

    //public function show(GetAccommodationRequest $request)
    public function show($slug)
    {
        $accommodation = Accommodation::where('slug', $slug)
            ->first();
        return new AccommodationResource($accommodation);
    }

    public function update(UpdateAccommodationRequest $request, $id)
    {
        $accommodation = $this->accommodationInterface->update($id, $request);
        return response()->json($accommodation, 200);

        // $accommodation = Accommodation::create( $request->all() );
        // return new AccommodationResource( $accommodation );
    }

    public function store(StoreAccommodationRequest $request)
    {
        $accommodation = $this->accommodationInterface->store($request);
        return response()->json($accommodation, 200);

        // $accommodation = Accommodation::create( $request->all() );
        // return new AccommodationResource( $accommodation );
    }

    public function destroy(DestroyAccommodationRequest $request)
    {
        $accommodation = $this->accommodationInterface->destroy($request);
        return response()->json([
            'message' => 'Accommodation deleted successfully',
            'data' => $accommodation
        ], 200);
    }
}