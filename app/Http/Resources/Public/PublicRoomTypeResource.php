<?php

namespace App\Http\Resources\Public;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista pública de un tipo de habitación. Muestra sólo lo necesario para
 * cotizar y reservar; nada de campos operativos internos.
 */
class PublicRoomTypeResource extends JsonResource
{
    public function toArray($request): array
    {
        $languageId = (string) (request()->get('language_id') ?: 'es');

        $translation = $this->relationLoaded('descriptions')
            ? ($this->descriptions->firstWhere('language_id', $languageId) ?? $this->descriptions->first())
            : $this->descriptions()->where('language_id', $languageId)->first();

        return [
            'id' => $this->id,
            'name' => $translation->name ?? $this->name,
            'description' => $translation->description ?? $this->description,
            'size' => $this->size,
            'max_occupancy' => $this->max_occupancy,
            'standard_occupancy' => $this->standard_occupancy,
            'category_id' => $this->category_id,
            'images' => PublicImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
