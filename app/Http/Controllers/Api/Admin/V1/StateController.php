<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Resources\Admin\StateResource;
use App\Models\State;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de provincias para los selectores del panel.
 *
 * Sin paginar ni buscar a propósito: acotado a un país (`api.catalog_country_iso`)
 * son 24 filas y entran enteras en un <select>. Sin ese filtro serían 4119, de
 * ~246 países, y el <select> deja de ser usable — ver el comentario en la config
 * antes de desactivarlo.
 */
class StateController extends BaseController
{
    public function index()
    {
        $states = State::query()
            ->when(
                $this->catalogCountryId(),
                fn ($q, $countryId) => $q->where('country_id', $countryId)
            )
            ->orderBy('name')
            ->get();

        return StateResource::collection($states);
    }

    /**
     * Id del país del catálogo, resuelto por ISO.
     *
     * Se resuelve por `iso_code` y no se hardcodea el id porque `countries` es
     * una tabla legacy del dump: el id de Argentina (10) es un dato de esa carga,
     * no algo que el código deba dar por sentado.
     *
     * Devuelve null si la config está vacía o el ISO no existe. Las dos veces el
     * efecto es no filtrar: es preferible un selector largo a uno vacío, que
     * parecería que no hay provincias cargadas.
     */
    private function catalogCountryId(): ?int
    {
        $iso = config('api.catalog_country_iso');

        if (blank($iso)) {
            return null;
        }

        $id = DB::table('countries')->where('iso_code', $iso)->value('id');

        return $id ? (int) $id : null;
    }
}
