<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class RatePlanResourceCollection extends ResourceCollection
{
    public $collects = RatePlanResource::class;

    public function toArray($request)
    {
        if ($this->resource instanceof \Illuminate\Pagination\AbstractPaginator) {
            return [
                'data' => $this->collection,
                'meta' => [
                    'current_page' => $this->currentPage(),
                    'per_page' => $this->perPage(),
                    'total' => $this->total(),
                    'last_page' => $this->lastPage(),
                ],
            ];
        }

        return ['data' => $this->collection];
    }
}
