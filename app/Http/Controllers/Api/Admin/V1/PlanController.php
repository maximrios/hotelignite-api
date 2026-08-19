<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Resources\Admin\PlanResource;
use App\Models\Plan;
use Illuminate\Routing\Controller as BaseController;

/**
 * Catálogo de planes para el selector del panel.
 *
 * Devuelve **todos**, incluidos los que no son públicos: el staff asigna planes
 * que el sitio comercial no lista. Filtrar por `is_public` acá dejaría cuentas
 * imposibles de editar desde el CRM.
 */
class PlanController extends BaseController
{
    public function index()
    {
        return PlanResource::collection(Plan::orderBy('sort_order')->orderBy('id')->get());
    }
}
