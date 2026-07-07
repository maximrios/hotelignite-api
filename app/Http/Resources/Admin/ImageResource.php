<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class ImageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'    => $this->id,
            'url'   => $this->url,
            'alt'   => $this->alt,
            'order' => $this->order,
        ];
    }
}
