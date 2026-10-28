<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Requests\Inventory\StoreRoomsRequest;
use App\Http\Requests\Inventory\UpdateInventoryRoomRequest;
use App\Http\Requests\Inventory\UpsertRoomTypeRequest;
use Illuminate\Http\Request;

interface InventoryInterface
{
    public function show(Request $request): array;

    public function storeType(UpsertRoomTypeRequest $request): array;

    public function updateType(int $id, UpsertRoomTypeRequest $request): array;

    public function destroyType(int $id): void;

    public function storeRooms(StoreRoomsRequest $request): array;

    public function updateRoom(int $id, UpdateInventoryRoomRequest $request): array;

    public function destroyRoom(int $id): array;

    public function restoreRoom(int $id): array;
}
