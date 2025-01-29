<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\AccommodationTypeResourceCollection;
use Illuminate\Http\Request;
use App\Models\AccommodationType;
use App\Repositories\Contracts\AccommodationTypeInterface;

class AccommodationTypeRepository implements AccommodationTypeInterface
{
    public function all(Request $request)
    {
        $types = AccommodationType::orderBy('name')->paginate();
        return new AccommodationTypeResourceCollection($types);
    }
}
