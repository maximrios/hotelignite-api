<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Models\Accommodation;
use App\Models\Image;
use App\Http\Resources\Admin\ImageResource;
use App\Http\Requests\Admin\StoreAccommodationImageRequest;
use App\Http\Requests\Admin\UpdateAccommodationImageRequest;
use App\Http\Requests\Admin\ReorderAccommodationImagesRequest;
use Illuminate\Routing\Controller as BaseController;

class AccommodationImageController extends BaseController
{
    public function index(Accommodation $accommodation)
    {
        return ImageResource::collection($accommodation->images);
    }

    public function store(StoreAccommodationImageRequest $request, Accommodation $accommodation)
    {
        $data = $request->validated();
        $data['order'] ??= $accommodation->images()->max('order') + 1;

        $image = $accommodation->images()->create($data);

        return new ImageResource($image);
    }

    public function update(UpdateAccommodationImageRequest $request, Accommodation $accommodation, Image $image)
    {
        $this->abortIfNotOwned($accommodation, $image);

        $image->update($request->validated());

        return new ImageResource($image->fresh());
    }

    public function reorder(ReorderAccommodationImagesRequest $request, Accommodation $accommodation)
    {
        $ids = collect($request->validated('images'))->pluck('id');

        $accommodation->images()->whereIn('id', $ids)->get()
            ->each(function (Image $image) use ($request) {
                $order = collect($request->validated('images'))
                    ->firstWhere('id', $image->id)['order'];
                $image->update(['order' => $order]);
            });

        return ImageResource::collection($accommodation->images()->orderBy('order')->get());
    }

    public function destroy(Accommodation $accommodation, Image $image)
    {
        $this->abortIfNotOwned($accommodation, $image);

        $image->delete();

        return response()->json(['message' => 'Image deleted successfully'], 200);
    }

    private function abortIfNotOwned(Accommodation $accommodation, Image $image): void
    {
        if ($image->imageable_type !== Accommodation::class || $image->imageable_id !== $accommodation->id) {
            abort(404);
        }
    }
}
