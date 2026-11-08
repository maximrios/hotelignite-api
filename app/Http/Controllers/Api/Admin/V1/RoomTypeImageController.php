<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\ReorderAccommodationImagesRequest;
use App\Http\Requests\Admin\StoreAccommodationImageRequest;
use App\Http\Requests\Admin\UpdateAccommodationImageRequest;
use App\Http\Resources\Admin\ImageResource;
use App\Models\Accommodation;
use App\Models\Image;
use App\Models\RoomType;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Fotos de un tipo de habitación: las que muestra el motor de reservas en cada
 * tarjeta. Mismo contrato que la galería del hotel; la primera es la portada.
 * Permisos por el alojamiento dueño del tipo.
 */
class RoomTypeImageController extends BaseController
{
    use AuthorizesRequests;

    public function index(Accommodation $accommodation, RoomType $roomType)
    {
        $this->authorize('view', $accommodation);
        $this->abortIfForeignType($accommodation, $roomType);

        return ImageResource::collection($roomType->images);
    }

    public function store(StoreAccommodationImageRequest $request, Accommodation $accommodation, RoomType $roomType)
    {
        $this->authorize('update', $accommodation);
        $this->abortIfForeignType($accommodation, $roomType);

        $data = $request->validated();
        $data['order'] ??= (int) $roomType->images()->max('order') + 1;

        return new ImageResource($roomType->images()->create($data));
    }

    public function update(UpdateAccommodationImageRequest $request, Accommodation $accommodation, RoomType $roomType, Image $image)
    {
        $this->authorize('update', $accommodation);
        $this->abortIfForeignType($accommodation, $roomType);
        $this->abortIfNotOwned($roomType, $image);

        $image->update($request->validated());

        return new ImageResource($image->fresh());
    }

    public function reorder(ReorderAccommodationImagesRequest $request, Accommodation $accommodation, RoomType $roomType)
    {
        $this->authorize('update', $accommodation);
        $this->abortIfForeignType($accommodation, $roomType);

        $orders = $request->orders();

        $roomType->images()->whereIn('id', array_keys($orders))->get()
            ->each(fn (Image $image) => $image->update(['order' => $orders[$image->id]]));

        return ImageResource::collection($roomType->images()->get());
    }

    public function destroy(Accommodation $accommodation, RoomType $roomType, Image $image)
    {
        $this->authorize('update', $accommodation);
        $this->abortIfForeignType($accommodation, $roomType);
        $this->abortIfNotOwned($roomType, $image);

        $image->delete();

        return response()->json(['message' => 'Image deleted successfully'], 200);
    }

    private function abortIfForeignType(Accommodation $accommodation, RoomType $roomType): void
    {
        if ((int) $roomType->accommodation_id !== (int) $accommodation->id) {
            abort(404);
        }
    }

    private function abortIfNotOwned(RoomType $roomType, Image $image): void
    {
        if ($image->imageable_type !== RoomType::class || (int) $image->imageable_id !== (int) $roomType->id) {
            abort(404);
        }
    }
}
