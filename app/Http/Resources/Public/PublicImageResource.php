<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista pública de una imagen polimórfica (accommodation, room type, tour, city).
 */
class PublicImageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'url' => $this->url,
            'alt' => $this->alt,
            'order' => $this->order,
        ];
    }
}
