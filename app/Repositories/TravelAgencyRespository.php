<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Http\Request;
use App\Models\TravelAgency;
use App\Http\Resources\V1\TravelAgencyResource;
use App\Http\Resources\V1\TravelAgencyResourceCollection;
use App\Repositories\Contracts\TravelAgencyInterface;

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
