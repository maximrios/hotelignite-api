<?php

namespace App\Http\Controllers\Api\V1;

use App\Repositories\Contracts\AccommodationTypeInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class AccommodationTypeController extends BaseController
{

    protected AccommodationTypeInterface $accommodationTypeInterface;

    public function __construct(AccommodationTypeInterface $accommodationTypeInterface)
    {
        $this->accommodationTypeInterface = $accommodationTypeInterface;
    }

    public function index(Request $request)
    {
        $channels = $this->accommodationTypeInterface->all($request);
        return response()->json($channels, 200);
    }
}