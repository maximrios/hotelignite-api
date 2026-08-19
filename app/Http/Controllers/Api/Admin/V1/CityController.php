<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Resources\Admin\CityResource;
use App\Models\City;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Catálogo de ciudades para los selectores del panel.
 *
 * Existe aparte de `Api\V1\CityController` porque ese devuelve las imágenes de
 * cada ciudad y topea en 20 sin decir que hay más: sirve para la web pública,
 * no para un combobox que busca mientras se tipea.
 */
class CityController extends BaseController
{
    /** Tope duro. El consumidor es un combobox, no un exportador. */
    private const MAX_LIMIT = 50;

    public function index(Request $request)
    {
        $limit = min(max($request->integer('limit', 20), 1), self::MAX_LIMIT);

        // Se piden `limit + 1` para saber si hay más sin pagar un COUNT(*) sobre
        // la tabla entera en cada tecla. El de más se descarta antes de serializar.
        $cities = City::with('state')
            ->when(
                $request->search,
                fn ($q, $search) => $q->where('name', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->limit($limit + 1)
            ->get();

        $hasMore = $cities->count() > $limit;

        return CityResource::collection($cities->take($limit))
            ->additional(['meta' => [
                'limit'    => $limit,
                'has_more' => $hasMore,
            ]]);
    }
}
