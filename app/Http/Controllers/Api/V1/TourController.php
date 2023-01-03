<?php

namespace App\Http\Controllers\Api\V1;

use App\Repositories\Contracts\TourInterface;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class TourController extends BaseController
{

    protected TourInterface $tourInterface;

    public function __construct(TourInterface $tourInterface)
    {
        $this->tourInterface = $tourInterface;
    }
    
    public function index(Request $request)
    {
        return response()->json( $this->tourInterface->all($request), 200);
        
    }

    public function show(Request $request)
    {
        return response()->json( $this->tourInterface->find($request->id), 200);
        
    }
}