<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista del dueño: un documento del legajo, con su tipo y con quién está
 * compartido. `disk`/`path` quedan ocultos por el modelo (referencias privadas).
 */
class DocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'document_type_id' => $this->document_type_id,
            'document_type' => $this->whenLoaded('documentType', fn () => $this->documentType?->name),
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),
            'shared_with' => $this->whenLoaded('shares', fn () => $this->shares->map(fn ($s) => [
                'client_id' => $s->client_id,
                'client_name' => $s->relationLoaded('client') ? $s->client?->name : null,
                'shared_at' => $s->shared_at,
                'verified_at' => $s->verified_at,
            ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
