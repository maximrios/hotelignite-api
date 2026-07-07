<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class RoomTypeResource extends JsonResource
{

    public function toArray($request)
    {
        // Obtener language_id del request (query param o header), por defecto 'es'
        $languageId = $request->get('language_id');
        
        // Si no está en query param, intentar obtener del header Accept-Language
        if (!$languageId) {
            $acceptLanguage = $request->header('Accept-Language', 'es');
            // Extraer solo los primeros 2 caracteres si viene como "es-ES" o "en-US"
            $languageId = substr($acceptLanguage, 0, 2);
        }
        
        // Por defecto 'es' si no se especifica
        $languageId = $languageId ?: 'es';

        // Buscar la descripción según el language_id
        $description = null;
        $name = null;
        
        if ($this->relationLoaded('descriptions')) {
            $roomTypeDescription = $this->descriptions
                ->where('language_id', $languageId)
                ->first();
            
            if ($roomTypeDescription) {
                $name = $roomTypeDescription->name;
                $description = $roomTypeDescription->description;
            } elseif ($this->descriptions->count() > 0) {
                // Si no existe para el language_id solicitado, usar la primera disponible
                $roomTypeDescription = $this->descriptions->first();
                $name = $roomTypeDescription->name;
                $description = $roomTypeDescription->description;
            }
        } elseif ($this->descriptions()->count() > 0) {
            // Si no está cargada la relación, hacer una consulta
            $roomTypeDescription = $this->descriptions()
                ->where('language_id', $languageId)
                ->first();
            
            if ($roomTypeDescription) {
                $name = $roomTypeDescription->name;
                $description = $roomTypeDescription->description;
            } else {
                // Si no existe para el language_id solicitado, usar la primera disponible
                $roomTypeDescription = $this->descriptions()->first();
                if ($roomTypeDescription) {
                    $name = $roomTypeDescription->name;
                    $description = $roomTypeDescription->description;
                }
            }
        }

        // Si no hay descripciones, usar los valores por defecto del modelo
        if (!$name) {
            $name = $this->name;
        }
        if (!$description) {
            $description = $this->description;
        }

        return [
            'id' => $this->id,
            'name' => $name,
            'description' => $description,
            'size' => $this->size ?? null,
            'max_occupancy' => $this->max_occupancy,
            'standard_occupancy' => $this->standard_occupancy,
            'quantity' => $this->quantity ?? $this->quantity_max,
            'status' => $this->status,
            'accommodation_id' => $this->accommodation_id,
            'category_id' => $this->category_id,
            'category' => $this->category ? $this->category->name : null,
            'accommodation' => $this->accommodation ? [
                'id' => $this->accommodation->id,
                'name' => $this->accommodation->name,
            ] : null,
            'images' => $this->images,
            'services' => $this->services,
        ];
    }
}
