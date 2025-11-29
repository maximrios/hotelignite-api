<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class PolicyResourceCollection extends ResourceCollection
{
    public $collects = PolicyResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
    }
}





