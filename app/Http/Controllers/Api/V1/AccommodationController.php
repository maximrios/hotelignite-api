<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Models\Accommodation;
use Illuminate\Http\JsonResponse;
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
    
    public function index(Request $request)
    {
        return response()->json( $this->accommodationInterface->all($request), 200);
        
    }

    public function show(Request $request)
    {
        return response()->json( $this->accommodationInterface->find($request->id), 200);
        
    }
}