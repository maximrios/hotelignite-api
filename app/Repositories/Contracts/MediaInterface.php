<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Http\Resources\Admin\MediaResource;
use App\Models\Contracts\HasMedia;
use App\Models\Media;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

interface MediaInterface
{
    /**
     * Agrega una imagen o un video de YouTube al final de la galería.
     *
     * @param  array{type: string, url: string, thumbnail_url?: string|null, alt?: string|null}  $data
     */
    public function add(HasMedia $owner, array $data): MediaResource;

    /** @param  list<int>  $ids  todos los de la galería, en el orden nuevo */
    public function reorder(HasMedia $owner, array $ids): AnonymousResourceCollection;

    public function remove(HasMedia $owner, Media $media): void;
}
