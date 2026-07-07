<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRateRequest;
use App\Http\Requests\UpdateRateRequest;
use App\Http\Requests\DestroyRateRequest;

interface RateInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);
    public function store(StoreRateRequest $request);
    public function update($id, UpdateRateRequest $request);
    public function destroy(DestroyRateRequest $request);
}
