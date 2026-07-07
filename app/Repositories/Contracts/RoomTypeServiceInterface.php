<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRoomTypeServiceRequest;
use App\Http\Requests\UpdateRoomTypeServiceRequest;
use App\Http\Requests\DestroyRoomTypeServiceRequest;

interface RoomTypeServiceInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);

    public function update($id, UpdateRoomTypeServiceRequest $request);
    public function store(StoreRoomTypeServiceRequest $request);
    public function destroy(DestroyRoomTypeServiceRequest $request);
}

