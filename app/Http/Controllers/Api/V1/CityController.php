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
        return $this->cityInterface->all($request);
    }

    public function show(string $slug)
    {
        return $this->cityInterface->show($slug);
    }
}