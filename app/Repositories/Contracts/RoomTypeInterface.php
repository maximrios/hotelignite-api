<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRoomTypeRequest;
use App\Http\Requests\UpdateRoomTypeRequest;
use App\Http\Requests\DestroyRoomTypeRequest;

interface RoomTypeInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);

    public function update($id, UpdateRoomTypeRequest $request);
    public function store(StoreRoomTypeRequest $request);
    public function destroy(DestroyRoomTypeRequest $request);
}












