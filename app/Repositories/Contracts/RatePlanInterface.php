<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRatePlanRequest;
use App\Http\Requests\UpdateRatePlanRequest;
use App\Http\Requests\DestroyRatePlanRequest;

interface RatePlanInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);
    public function store(StoreRatePlanRequest $request);
    public function update($id, UpdateRatePlanRequest $request);
    public function destroy(DestroyRatePlanRequest $request);
}
