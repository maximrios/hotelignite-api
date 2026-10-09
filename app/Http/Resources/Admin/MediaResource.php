<?php

namespace App\Http\Resources\Admin;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `embed_url` sólo para YouTube, sobre `youtube-nocookie.com`: el frontend lo
 * mete en un iframe tal cual y no tiene que parsear nada.
 *
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'provider' => $this->provider,
            'url' => $this->url,
            'provider_id' => $this->provider_id,
            'thumbnail_url' => $this->thumbnail_url,
            'embed_url' => $this->provider === Media::PROVIDER_YOUTUBE && $this->provider_id
                ? "https://www.youtube-nocookie.com/embed/{$this->provider_id}"
                : null,
            'alt' => $this->alt,
            'order' => $this->order,
        ];
    }
}
