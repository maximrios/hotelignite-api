<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\DestroyRatePlanRequest;
use App\Http\Requests\StoreRatePlanRequest;
use App\Http\Requests\UpdateRatePlanRequest;
use Illuminate\Http\Request;

interface RatePlanInterface
{
    public function all(Request $request);

    public function find($id);

    public function search($request);

    public function store(StoreRatePlanRequest $request);

    public function update($id, UpdateRatePlanRequest $request);

    public function destroy(DestroyRatePlanRequest $request);
}
