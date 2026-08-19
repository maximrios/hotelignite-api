<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\TravelAgencyResource;
use App\Http\Resources\V1\TravelAgencyResourceCollection;
use App\Models\TravelAgency;
use App\Repositories\Contracts\TravelAgencyInterface;
use Illuminate\Http\Request;

class TravelAgencyRespository implements TravelAgencyInterface
{
    public function all(Request $request)
    {
        $agencies = TravelAgency::all();

        return new TravelAgencyResourceCollection($agencies);
    }

    public function find($id)
    {
        $accommodation = TravelAgency::find($id);

        return new TravelAgencyResource($accommodation);
    }
}
