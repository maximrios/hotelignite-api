<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Sin `toArray()` propio: al serializar un paginator Laravel ya inyecta su
 * bloque de paginación, y declarar `meta` acá duplicaría las claves. Mismo
 * criterio que `AccommodationResourceCollection`.
 */
class ChannelResourceCollection extends ResourceCollection
{
    public $collects = ChannelResource::class;
}
