<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\Admin\MediaResource;
use App\Models\Contracts\HasMedia;
use App\Models\Media;
use App\Repositories\Contracts\MediaInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Galería polimórfica (docs/events-plan.md). Imágenes por URL —subidas a
 * Cloudinary por el frontend— y videos de YouTube, guardados como URL + id.
 */
class MediaRepository implements MediaInterface
{
    /** Tope por galería: es una ficha, no un archivo de fotos. */
    private const MAX_ITEMS = 30;

    public function add(HasMedia $owner, array $data): MediaResource
    {
        if ($owner->media()->count() >= self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'url' => 'La galería admite hasta '.self::MAX_ITEMS.' elementos.',
            ]);
        }

        $attributes = $data['type'] === Media::TYPE_VIDEO
            ? $this->youTube($data['url'])
            : $this->image($data['url'], $data['thumbnail_url'] ?? null);

        $media = $owner->media()->create([
            ...$attributes,
            'alt' => $data['alt'] ?? null,
            'order' => ((int) $owner->media()->max('order')) + 1,
        ]);

        return new MediaResource($media);
    }

    public function reorder(HasMedia $owner, array $ids): AnonymousResourceCollection
    {
        $current = $owner->media()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $sent = collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();

        // Exactamente los mismos: un id ajeno movería media de otro evento, y uno
        // faltante dejaría dos elementos con el mismo orden.
        if ($current !== $sent) {
            throw ValidationException::withMessages([
                'ids' => 'Mandá todos los elementos de la galería, y sólo esos.',
            ]);
        }

        DB::transaction(function () use ($owner, $ids) {
            foreach (array_values($ids) as $position => $id) {
                $owner->media()->whereKey($id)->update(['order' => $position]);
            }
        });

        return MediaResource::collection($owner->media()->get());
    }

    public function remove(HasMedia $owner, Media $media): void
    {
        abort_unless($owner->media()->whereKey($media->id)->exists(), 404);

        $media->delete();
    }

    /** @return array<string, string|null> */
    private function youTube(string $url): array
    {
        $id = (string) Media::youTubeId($url);

        return [
            'type' => Media::TYPE_VIDEO,
            'provider' => Media::PROVIDER_YOUTUBE,
            'url' => "https://www.youtube.com/watch?v={$id}",
            'provider_id' => $id,
            'thumbnail_url' => "https://i.ytimg.com/vi/{$id}/hqdefault.jpg",
        ];
    }

    /** @return array<string, string|null> */
    private function image(string $url, ?string $thumbnail): array
    {
        $isCloudinary = strtolower((string) parse_url($url, PHP_URL_HOST)) === 'res.cloudinary.com';

        return [
            'type' => Media::TYPE_IMAGE,
            'provider' => $isCloudinary ? Media::PROVIDER_CLOUDINARY : Media::PROVIDER_EXTERNAL,
            'url' => $url,
            'provider_id' => null,
            // Cloudinary transforma por URL: la miniatura sale sin subir otra imagen.
            'thumbnail_url' => $thumbnail ?? ($isCloudinary
                ? str_replace('/upload/', '/upload/c_fill,w_400,h_300/', $url)
                : null),
        ];
    }
}
