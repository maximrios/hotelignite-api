<?php

namespace App\Http\Controllers\Api\V1;

use App\Repositories\TravelerRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/** Registro de pasajeros y directorio de huéspedes del PMS. Ver TravelerRepository. */
class TravelerController extends BaseController
{
    public function __construct(private TravelerRepository $travelers) {}

    public function registry(Request $request)
    {
        return response()->json($this->travelers->registry($request));
    }

    public function guests(Request $request)
    {
        return response()->json($this->travelers->guests($request));
    }

    public function guest(Request $request, int $id)
    {
        return response()->json($this->travelers->guest($id, $request));
    }
}
