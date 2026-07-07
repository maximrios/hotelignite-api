<?php

namespace App\Repositories\Contracts;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRoomTypeBedRequest;
use App\Http\Requests\UpdateRoomTypeBedRequest;
use App\Http\Requests\DestroyRoomTypeBedRequest;

interface RoomTypeBedInterface
{
    public function all(Request $request);
    public function find($id);
    public function search($request);
    public function store(StoreRoomTypeBedRequest $request);
    public function update($id, UpdateRoomTypeBedRequest $request);
    public function destroy(DestroyRoomTypeBedRequest $request);
}
