<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * No declarar `meta` en un `toArray()` acá: al serializar un paginator, Laravel
 * ya inyecta su propio bloque de paginación, y las claves duplicadas se fusionan
 * en arrays (`"total": [76, 76]`) en vez de pisarse. Dejar que lo arme el
 * framework mantiene la forma alineada con el resto de /api/admin/v1.
 */
class AccommodationResourceCollection extends ResourceCollection
{
    public $collects = AccommodationResource::class;
}
