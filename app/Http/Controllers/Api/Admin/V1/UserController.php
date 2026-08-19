<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Gestión de usuarios de plataforma, de cuentas hoteleras y de clients B2B
 * (solo plataforma). Ver docs/users-crud-plan.md.
 *
 * La tenencia (`user_type` + `account_id` + `client_id`) es control de acceso,
 * no metadata: se normaliza siempre en User::normalizeTenancy() para que un
 * usuario no quede con el id de un tipo que ya no tiene.
 */
class UserController extends BaseController
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $search = $request->query('search');

        $users = User::query()
            ->when($request->filled('search'), fn ($q) => $q->where(
                fn ($sub) => $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
            ))
            ->when($request->filled('user_type'), fn ($q) => $q->where('user_type', $request->query('user_type')))
            ->when($request->filled('account_id'), fn ($q) => $q->where('account_id', $request->integer('account_id')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->orderByDesc('id')
            ->paginate($request->integer('limit', 15));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request)
    {
        $this->authorize('create', User::class);

        // Transacción: el user y su acotamiento se crean juntos o no se crean. Sin
        // esto, si el sync del pivote falla (ej. tabla ausente), el User::create ya
        // commiteó y queda un user a medio crear mientras el cliente ve un 500.
        $user = DB::transaction(function () use ($request) {
            $user = User::create(array_merge(
                $request->tenancyAttributes(),
                ['password' => Hash::make($request->validated()['password'])]
            ));

            // El acotamiento por alojamiento vive en un pivote, no en una columna,
            // así que se sincroniza aparte. Vacío = ve toda la cuenta (sin filas).
            $user->accommodations()->sync($request->accommodationIds());

            return $user;
        });

        return (new UserResource($user->load('accommodations')))->response()->setStatusCode(201);
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        // El CRM precarga el acotamiento en el form de edición: sin el eager-load
        // el Resource omite `accommodations` y el form arranca creyendo "toda la
        // cuenta" aunque el user esté acotado.
        return new UserResource($user->load('accommodations'));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->authorize('update', $user);

        $previousTenancy = [
            'user_type' => $user->user_type,
            'account_id' => $user->account_id,
            'client_id' => $user->client_id,
        ];

        $attributes = $request->tenancyAttributes();

        $password = $request->validated()['password'] ?? null;
        if (filled($password)) {
            $attributes['password'] = Hash::make($password);
        }

        DB::transaction(function () use ($request, $user, $attributes, $previousTenancy) {
            $user->update($attributes);
            $this->syncAccommodationScope($request, $user, $previousTenancy);
        });

        $this->auditTenancyChange($request, $user, $previousTenancy);

        return new UserResource($user->fresh()->load('accommodations'));
    }

    /**
     * Sincroniza el acotamiento por alojamiento tras el update.
     *
     * Sólo `account` lleva acotamiento, así que dejar de serlo lo limpia. Y si el
     * user cambió de cuenta sin tocar los alojamientos, el pivote quedó apuntando
     * a alojamientos de la cuenta anterior —otro tenant— y hay que limpiarlo: la
     * validación no lo cubre porque sólo mira los ids que *vienen* en el payload.
     *
     * @param  array<string, mixed>  $previousTenancy
     */
    private function syncAccommodationScope(UpdateUserRequest $request, User $user, array $previousTenancy): void
    {
        if (! $user->isAccount()) {
            $user->accommodations()->sync([]);

            return;
        }

        if ($request->touchesAccommodationScope()) {
            $user->accommodations()->sync($request->accommodationIds());

            return;
        }

        if ($user->account_id !== $previousTenancy['account_id']) {
            $user->accommodations()->sync([]);
        }
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        // Revocar antes de la baja: si no, los tokens vigentes siguen
        // autenticando hasta expirar y la baja queda cosmética.
        $user->revokeApiTokens();
        $user->delete();

        Log::info('admin.users.deleted', [
            'actor_id' => $request->user()?->id,
            'user_id' => $user->id,
            'user_type' => $user->user_type,
        ]);

        return response()->json(null, 204);
    }

    /**
     * Mover un usuario entre tipos cambia qué datos ve, así que queda registrado.
     * El proyecto no tiene tabla de auditoría: se usa el logging estructurado
     * existente (ver docs/users-crud-plan.md, "Decisiones abiertas").
     *
     * @param  array<string, mixed>  $previous
     */
    private function auditTenancyChange(UpdateUserRequest $request, User $user, array $previous): void
    {
        $current = [
            'user_type' => $user->user_type,
            'account_id' => $user->account_id,
            'client_id' => $user->client_id,
        ];

        if ($current === $previous) {
            return;
        }

        Log::info('admin.users.tenancy_changed', [
            'actor_id' => $request->user()?->id,
            'user_id' => $user->id,
            'from' => $previous,
            'to' => $current,
        ]);
    }
}
