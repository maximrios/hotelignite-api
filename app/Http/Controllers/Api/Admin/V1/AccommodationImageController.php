<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\ReorderAccommodationImagesRequest;
use App\Http\Requests\Admin\StoreAccommodationImageRequest;
use App\Http\Requests\Admin\UpdateAccommodationImageRequest;
use App\Http\Resources\Admin\ImageResource;
use App\Models\Accommodation;
use App\Models\Image;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller as BaseController;

class AccommodationImageController extends BaseController
{
    use AuthorizesRequests;

    public function index(Accommodation $accommodation)
    {
        $this->authorize('view', $accommodation);

        return ImageResource::collection($accommodation->images);
    }

    public function store(StoreAccommodationImageRequest $request, Accommodation $accommodation)
    {
        $this->authorize('update', $accommodation);

        $data = $request->validated();
        $data['order'] ??= (int) $accommodation->images()->max('order') + 1;

        $image = $accommodation->images()->create($data);

        return new ImageResource($image);
    }

    public function update(UpdateAccommodationImageRequest $request, Accommodation $accommodation, Image $image)
    {
        $this->authorize('update', $accommodation);

        $this->abortIfNotOwned($accommodation, $image);

        $image->update($request->validated());

        return new ImageResource($image->fresh());
    }

    public function reorder(ReorderAccommodationImagesRequest $request, Accommodation $accommodation)
    {
        $this->authorize('update', $accommodation);

        $orders = $request->orders();

        $accommodation->images()->whereIn('id', array_keys($orders))->get()
            ->each(fn (Image $image) => $image->update(['order' => $orders[$image->id]]));

        return ImageResource::collection($accommodation->images()->get());
    }

    public function destroy(Accommodation $accommodation, Image $image)
    {
        $this->authorize('update', $accommodation);

        $this->abortIfNotOwned($accommodation, $image);

        $image->delete();

        return response()->json(['message' => 'Image deleted successfully'], 200);
    }

    private function abortIfNotOwned(Accommodation $accommodation, Image $image): void
    {
        if ($image->imageable_type !== Accommodation::class || (int) $image->imageable_id !== (int) $accommodation->id) {
            abort(404);
        }
    }
}
