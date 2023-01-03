<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class TourResource extends JsonResource
{
    
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'web' => $this->web,
            'images' => $this->images,
            'operator' => ($this->channel) ? $this->channel->name:null
        ];
    }
}
