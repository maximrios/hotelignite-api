<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\StoreRoomsRequest;
use App\Http\Requests\Inventory\UpdateInventoryRoomRequest;
use App\Http\Requests\Inventory\UpsertRoomTypeRequest;
use App\Repositories\Contracts\InventoryInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/** Inventario del PMS (tipos + habitaciones físicas). Ver InventoryRepository. */
class InventoryController extends BaseController
{
    public function __construct(private InventoryInterface $inventory) {}

    public function show(Request $request)
    {
        $request->validate(['accommodation_id' => ['required', 'integer']]);

        return response()->json($this->inventory->show($request));
    }

    public function storeType(UpsertRoomTypeRequest $request)
    {
        return response()->json($this->inventory->storeType($request), 201);
    }

    public function updateType(UpsertRoomTypeRequest $request, int $id)
    {
        return response()->json($this->inventory->updateType($id, $request));
    }

    public function destroyType(int $id)
    {
        $this->inventory->destroyType($id);

        // JSON y no 204: el apiFetch del PMS siempre parsea el cuerpo.
        return response()->json(['deleted' => true]);
    }

    public function storeRooms(StoreRoomsRequest $request)
    {
        return response()->json($this->inventory->storeRooms($request), 201);
    }

    public function updateRoom(UpdateInventoryRoomRequest $request, int $id)
    {
        return response()->json($this->inventory->updateRoom($id, $request));
    }

    public function destroyRoom(int $id)
    {
        return response()->json($this->inventory->destroyRoom($id));
    }

    public function restoreRoom(int $id)
    {
        return response()->json($this->inventory->restoreRoom($id));
    }
}
