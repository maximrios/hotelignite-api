<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\StoreAccountRequest;
use App\Http\Requests\Admin\UpdateAccountRequest;
use App\Http\Resources\Admin\AccountResource;
use App\Models\Account;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

/**
 * Gestión de cuentas hoteleras (solo plataforma).
 *
 * Existe aparte de `Api\V1\AccountController` en vez de reusarlo porque ese
 * tiene tres problemas que lo hacen inservible acá:
 *
 *  1. No autoriza nada. Cualquier usuario autenticado —un hotelero de otra
 *     cuenta, un usuario de client B2B— podía listar, editar y borrar las 78
 *     cuentas.
 *  2. Su Resource devuelve `accounts.token`, un secreto de 40 caracteres, en
 *     todas las respuestas incluido el índice.
 *  3. Su forma de respuesta no coincide con el resto de /api/admin/v1: pagina
 *     con offset/limit, el `total` ignora los filtros aplicados (así que miente
 *     al buscar) y el `destroy` recibe el id por body de un `DELETE /accounts`
 *     sin id en la ruta.
 */
class AccountController extends BaseController
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Account::class);

        $search = $request->query('search');

        $accounts = Account::with(['plan', 'accountType'])
            ->withCount(['accommodations', 'users'])
            ->when($request->filled('search'), fn ($q) => $q->where(
                fn ($sub) => $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
            ))
            ->when($request->filled('account_type_id'), fn ($q) => $q->where('account_type_id', $request->integer('account_type_id')))
            ->when($request->filled('plan_id'), fn ($q) => $q->where('plan_id', $request->integer('plan_id')))
            ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate($request->integer('limit', 15));

        return AccountResource::collection($accounts);
    }

    public function show(Account $account)
    {
        $this->authorize('view', $account);

        $account->load(['plan', 'accountType'])->loadCount(['accommodations', 'users']);

        return new AccountResource($account);
    }

    public function store(StoreAccountRequest $request)
    {
        $this->authorize('create', Account::class);

        $account = Account::create($request->validated());
        $account->load(['plan', 'accountType'])->loadCount(['accommodations', 'users']);

        return (new AccountResource($account))->response()->setStatusCode(201);
    }

    public function update(UpdateAccountRequest $request, Account $account)
    {
        $this->authorize('update', $account);

        $account->update($request->validated());
        $account->load(['plan', 'accountType'])->loadCount(['accommodations', 'users']);

        return new AccountResource($account);
    }

    /**
     * Baja de una cuenta vacía.
     *
     * `AccountPolicy::delete()` bloquea las que todavía tienen alojamientos o
     * usuarios: sin FKs en la base, borrarlas dejaría esos registros apuntando a
     * un `account_id` inexistente y invisibles para todos. El 403 que devuelve
     * ese caso es la respuesta correcta, no un permiso faltante.
     */
    public function destroy(Request $request, Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        $account->delete();

        Log::info('admin.accounts.deleted', [
            'actor_id' => $request->user()?->id,
            'account_id' => $account->id,
        ]);

        return response()->json(null, 204);
    }
}
