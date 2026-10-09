<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

/** Misma `meta` que el resto de admin/v1 (`PagedResponse` del CRM). */
class EventResourceCollection extends ResourceCollection
{
    public $collects = EventResource::class;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof AbstractPaginator) {
            return [
                'data' => $this->collection,
                'meta' => [
                    'current_page' => $this->currentPage(),
                    'per_page' => $this->perPage(),
                    'total' => $this->total(),
                    'last_page' => $this->lastPage(),
                    'from' => $this->firstItem(),
                    'to' => $this->lastItem(),
                ],
            ];
        }

        return ['data' => $this->collection];
    }
}
