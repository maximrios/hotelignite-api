<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use App\Models\Accommodation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Utilidades de tenencia para los repositorios de recursos hijos.
 *
 * El usuario se toma del guard y no de un parámetro porque varios métodos del
 * contrato (find, destroy) no reciben el Request. Sirve tanto para usuarios
 * Sanctum como para los clients B2B, que AuthenticateApiClient deja resueltos
 * en el guard como un User en memoria.
 */
trait ScopesToAccommodation
{
    protected function tenant(): User
    {
        $user = Auth::user();

        abort_if($user === null, 401);

        return $user;
    }

    /**
     * Resuelve el alojamiento destino de una escritura. Exige que sea visible
     * para el usuario (404 si no lo es, para no delatar su existencia) y que
     * además pueda editarlo (403 — acá caen los clients, que son read-only).
     */
    protected function writableAccommodation($accommodationId): Accommodation
    {
        $accommodation = Accommodation::visibleTo($this->tenant())->find($accommodationId);

        abort_if($accommodation === null, 404);
        Gate::authorize('update', $accommodation);

        return $accommodation;
    }

    /**
     * Igual que la anterior, pero sólo exige poder ver el alojamiento. Es lo que
     * corresponde cuando la escritura la hace un tercero legítimo sobre el
     * alojamiento — un portal B2B creando una pre-reserva, por ejemplo.
     */
    protected function visibleAccommodation($accommodationId): Accommodation
    {
        $accommodation = Accommodation::visibleTo($this->tenant())->find($accommodationId);

        abort_if($accommodation === null, 404);

        return $accommodation;
    }
}
