<?php

namespace App\Http\Resources\ClientPanel;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista del client: un documento que le compartieron. Recortada a propósito — no
 * expone `disk`/`path` ni quién más lo tiene. El binario se baja por el endpoint
 * de download (valida el share en cada request), no por una URL directa.
 */
class SharedDocumentResource extends JsonResource
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
            'download_url' => route('client-panel.documents.download', [
                'accommodation' => $this->documentable_id,
                'document' => $this->id,
            ]),
        ];
    }
}
