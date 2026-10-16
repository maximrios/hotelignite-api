<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Resources\Admin\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\Rule;

/**
 * Catálogo de planes para el selector del panel.
 *
 * Devuelve **todos**, incluidos los que no son públicos: el staff asigna planes
 * que el sitio comercial no lista. Filtrar por `is_public` acá dejaría cuentas
 * imposibles de editar desde el CRM.
 *
 * Por default sólo los de hoteleros (`audience=accommodation`): los selectores
 * de cuenta y alojamiento no tienen que ofrecer un plan de municipio.
 */
class PlanController extends BaseController
{
    public function index(Request $request)
    {
        $audience = $request->query('audience', Plan::AUDIENCE_ACCOMMODATION);
        abort_unless(in_array($audience, [Plan::AUDIENCE_ACCOMMODATION, Plan::AUDIENCE_CLIENT], true), 422, 'audience inválido.');

        return PlanResource::collection(
            Plan::forAudience($audience)->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    /**
     * Precio y estado comercial. Los precios de clients arrancan provisorios y
     * se calibran contra el jornal vigente; por eso se editan acá y no en código.
     */
    public function update(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:150'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'billing_period' => ['sometimes', Rule::in(['free', 'monthly', 'yearly'])],
            'price_provisional' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $plan->update($data);

        return new PlanResource($plan->fresh());
    }
}
