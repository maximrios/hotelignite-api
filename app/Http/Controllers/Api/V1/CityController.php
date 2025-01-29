<?php

namespace App\Http\Controllers\Api\V1;

use App\Repositories\Contracts\CityInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class CityController extends BaseController
{

    protected CityInterface $cityInterface;

    public function __construct(CityInterface $cityInterface)
    {
        $this->cityInterface = $cityInterface;
    }

    public function index(Request $request)
    {
        $channels = $this->cityInterface->all($request);
        return response()->json($channels, 200);
    }
}