<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\TourResource;
use App\Http\Resources\V1\TourResourceCollection;
use Illuminate\Http\Request;
use App\Models\Tour;
use App\Repositories\Contracts\TourInterface;

class TourRepository implements TourInterface
{
    public function all(Request $request)
    {
        $tours = Tour::all();
        return new TourResourceCollection($tours);
    }
    public function find($id)
    {
        $tour = Tour::find($id);
        return new TourResource($tour);
    }
}
