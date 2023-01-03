<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use App\Repositories\Contracts\TravelAgencyInterface;

class TravelAgencyController extends BaseController
{

    protected TravelAgencyInterface $agencyInterface;

    public function __construct(TravelAgencyInterface $agencyInterface)
    {
        $this->agencyInterface = $agencyInterface;
    }
    
    public function index(Request $request)
    {
        return response()->json( $this->agencyInterface->all($request), 200);
        
    }

    public function show(Request $request)
    {
        return response()->json( $this->agencyInterface->find($request->id), 200);
        
    }
}