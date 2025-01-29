<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\AccommodationTypeResourceCollection;
use Illuminate\Http\Request;
use App\Models\AccommodationType;
use App\Models\Service;
use App\Repositories\Contracts\AccommodationTypeInterface;
use App\Repositories\Contracts\ServiceInterface;

class ServiceRepository implements ServiceInterface
{
    public function all(Request $request)
    {
        $types = Service::orderBy('name')->paginate();
        return new AccommodationTypeResourceCollection($types);
    }
}
