<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ServiceResourceCollection extends ResourceCollection
{
    public $collects = ServiceResource::class;

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
                    // `from`/`to` completan la forma que espera `PagedResponse`
                    // del CRM (y que ya devuelve el resto de admin/v1). Son
                    // null en una página vacía, igual que en Laravel.
                    'from' => $this->firstItem(),
                    'to' => $this->lastItem(),
                ],
            ];
        }

        return ['data' => $this->collection];
    }
}
