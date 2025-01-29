<?php

namespace App\Http\Controllers\Api\V1;

use App\Repositories\Contracts\ServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class ServiceController extends BaseController
{

    protected ServiceInterface $serviceInterface;

    public function __construct(ServiceInterface $serviceInterface)
    {
        $this->serviceInterface = $serviceInterface;
    }

    public function index(Request $request)
    {
        $channels = $this->serviceInterface->all($request);
        return response()->json($channels, 200);
    }
}