<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Plan;
use Illuminate\Routing\Controller as BaseController;

class PlanController extends BaseController
{
    public function index()
    {
        return response()->json(Plan::orderBy('name')->get(), 200);
    }
}
