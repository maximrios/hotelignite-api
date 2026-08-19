<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\StateResource;
use App\Models\State;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Provincias / estados. La tabla es global (todos los países), por eso el
 * listado se filtra por `country_id` (ej. Argentina = 10). Sólo lectura.
 */
class StateController extends BaseController
{
    public function index(Request $request)
    {
        $states = State::query()
            ->when($request->filled('country_id'), fn ($q) => $q->where('country_id', $request->query('country_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->query('search').'%'))
            ->orderBy('name')
            ->get();

        return StateResource::collection($states);
    }
}
