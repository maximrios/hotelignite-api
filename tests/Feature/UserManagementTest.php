<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD de users bajo /api/admin/v1/users (docs/users-crud-plan.md).
 *
 * Lo que importa acá es la tenencia cruzada: `user_type` + `account_id` +
 * `client_id` son el control de acceso de toda la API, y un account_id mal
 * asignado le da al usuario visibilidad sobre alojamientos de otra cuenta.
 *
 * Usa DatabaseTransactions (no RefreshDatabase) porque el schema viene de un
 * dump con datos reales: RefreshDatabase haría migrate:fresh y lo borraría.
 */
class UserManagementTest extends TestCase
{
    use DatabaseTransactions;

    private Account $account;

    private Client $client;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Cuenta test users']);
        $this->client = Client::create(['name' => 'Client test users']);

        $this->platform = $this->makeUser(User::TYPE_PLATFORM);
    }

    private function makeUser(string $type, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test '.$type,
            'email' => $type.'-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => $type,
            'account_id' => $type === User::TYPE_ACCOUNT ? $this->account->id : null,
            'client_id' => $type === User::TYPE_CLIENT ? $this->client->id : null,
        ], $overrides));
    }

    private function makeAccommodation(Account $account): Accommodation
    {
        return Accommodation::create([
            'account_id' => $account->id,
            'city_id' => $this->anyCityId(),
            'name' => 'Aloj '.uniqid(),
            'slug' => 'aloj-'.uniqid(),
        ]);
    }

    /** accommodations.city_id es NOT NULL sin default en el schema real. */
    private function anyCityId(): int
    {
        return City::query()->value('id')
            ?? DB::table('cities')->insertGetId([
                'name' => 'Ciudad test',
                'slug' => 'ciudad-test-'.uniqid(),
            ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nuevo usuario',
            'email' => 'nuevo-'.uniqid().'@test.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'user_type' => User::TYPE_PLATFORM,
        ], $overrides);
    }

    // --- Acceso: solo plataforma -------------------------------------------

    public function test_un_account_no_puede_listar_ni_crear_usuarios(): void
    {
        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT));

        $this->getJson('/api/admin/v1/users')->assertForbidden();
        $this->postJson('/api/admin/v1/users', $this->payload())->assertForbidden();
    }

    public function test_un_client_no_puede_listar_ni_crear_usuarios(): void
    {
        Sanctum::actingAs($this->makeUser(User::TYPE_CLIENT));

        $this->getJson('/api/admin/v1/users')->assertForbidden();
        $this->postJson('/api/admin/v1/users', $this->payload())->assertForbidden();
    }

    // --- Invariantes de tenencia -------------------------------------------

    public function test_crear_un_platform_deja_la_tenencia_en_null(): void
    {
        Sanctum::actingAs($this->platform);

        // Se mandan ids a propósito: un platform bypassa la tenencia, así que
        // deben descartarse en vez de quedar colgados.
        $response = $this->postJson('/api/admin/v1/users', $this->payload([
            'user_type' => User::TYPE_PLATFORM,
            'account_id' => $this->account->id,
            'client_id' => $this->client->id,
        ]))->assertCreated();

        $response->assertJsonPath('data.user_type', User::TYPE_PLATFORM);
        $response->assertJsonPath('data.account_id', null);
        $response->assertJsonPath('data.client_id', null);

        $this->assertDatabaseHas('users', [
            'id' => $response->json('data.id'),
            'account_id' => null,
            'client_id' => null,
        ]);
    }

    public function test_crear_un_account_sin_account_id_falla(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/users', $this->payload([
            'user_type' => User::TYPE_ACCOUNT,
        ]))->assertStatus(422)->assertJsonValidationErrors('account_id');
    }

    public function test_crear_un_account_con_account_id_inexistente_falla(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/users', $this->payload([
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => 999999,
        ]))->assertStatus(422)->assertJsonValidationErrors('account_id');
    }

    public function test_crear_un_client_sin_client_id_falla(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/users', $this->payload([
            'user_type' => User::TYPE_CLIENT,
        ]))->assertStatus(422)->assertJsonValidationErrors('client_id');
    }

    public function test_cambiar_de_account_a_platform_limpia_el_account_id(): void
    {
        Sanctum::actingAs($this->platform);

        $target = $this->makeUser(User::TYPE_ACCOUNT);
        $this->assertNotNull($target->account_id);

        $this->patchJson("/api/admin/v1/users/{$target->id}", [
            'user_type' => User::TYPE_PLATFORM,
        ])->assertOk()
            ->assertJsonPath('data.user_type', User::TYPE_PLATFORM)
            ->assertJsonPath('data.account_id', null);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'user_type' => User::TYPE_PLATFORM,
            'account_id' => null,
            'client_id' => null,
        ]);
    }

    public function test_cambiar_de_platform_a_account_exige_account_id(): void
    {
        Sanctum::actingAs($this->platform);

        $target = $this->makeUser(User::TYPE_PLATFORM);

        $this->patchJson("/api/admin/v1/users/{$target->id}", [
            'user_type' => User::TYPE_ACCOUNT,
        ])->assertStatus(422)->assertJsonValidationErrors('account_id');
    }

    public function test_cambiar_de_account_a_client_migra_la_tenencia(): void
    {
        Sanctum::actingAs($this->platform);

        $target = $this->makeUser(User::TYPE_ACCOUNT);

        $this->patchJson("/api/admin/v1/users/{$target->id}", [
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $this->client->id,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'user_type' => User::TYPE_CLIENT,
            'account_id' => null,
            'client_id' => $this->client->id,
        ]);
    }

    // --- Acotamiento por alojamiento (pivote accommodation_user) ------------

    public function test_crear_un_account_con_accommodation_ids_los_acota(): void
    {
        Sanctum::actingAs($this->platform);

        $a1 = $this->makeAccommodation($this->account);
        $a2 = $this->makeAccommodation($this->account);

        $response = $this->postJson('/api/admin/v1/users', $this->payload([
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $this->account->id,
            'accommodation_ids' => [$a1->id, $a2->id],
        ]))->assertCreated();

        $userId = $response->json('data.id');

        $this->assertDatabaseHas('accommodation_user', ['user_id' => $userId, 'accommodation_id' => $a1->id]);
        $this->assertDatabaseHas('accommodation_user', ['user_id' => $userId, 'accommodation_id' => $a2->id]);
        $response->assertJsonCount(2, 'data.accommodations');
    }

    public function test_crear_un_account_sin_accommodation_ids_no_acota(): void
    {
        Sanctum::actingAs($this->platform);

        $response = $this->postJson('/api/admin/v1/users', $this->payload([
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $this->account->id,
        ]))->assertCreated();

        // Pivote vacío = ve toda la cuenta.
        $this->assertDatabaseMissing('accommodation_user', ['user_id' => $response->json('data.id')]);
        $response->assertJsonCount(0, 'data.accommodations');
    }

    public function test_no_se_puede_acotar_a_un_alojamiento_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->platform);

        $otraCuenta = Account::create(['name' => 'Otra cuenta '.uniqid()]);
        $ajeno = $this->makeAccommodation($otraCuenta);

        // La fuga que el pivote existe para evitar: scopear un user a datos de
        // otro tenant. `exists` no alcanza; la pertenencia a la cuenta se valida.
        $this->postJson('/api/admin/v1/users', $this->payload([
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $this->account->id,
            'accommodation_ids' => [$ajeno->id],
        ]))->assertStatus(422)->assertJsonValidationErrors('accommodation_ids');
    }

    public function test_un_no_account_con_accommodation_ids_es_rechazado(): void
    {
        Sanctum::actingAs($this->platform);

        $a1 = $this->makeAccommodation($this->account);

        $this->postJson('/api/admin/v1/users', $this->payload([
            'user_type' => User::TYPE_PLATFORM,
            'accommodation_ids' => [$a1->id],
        ]))->assertStatus(422)->assertJsonValidationErrors('accommodation_ids');
    }

    public function test_editar_el_acotamiento_sincroniza_el_pivote(): void
    {
        Sanctum::actingAs($this->platform);

        $a1 = $this->makeAccommodation($this->account);
        $a2 = $this->makeAccommodation($this->account);

        $target = $this->makeUser(User::TYPE_ACCOUNT);
        $target->accommodations()->sync([$a1->id]);

        // sync reemplaza el set completo: a1 sale, a2 entra.
        $this->patchJson("/api/admin/v1/users/{$target->id}", [
            'accommodation_ids' => [$a2->id],
        ])->assertOk();

        $this->assertDatabaseMissing('accommodation_user', ['user_id' => $target->id, 'accommodation_id' => $a1->id]);
        $this->assertDatabaseHas('accommodation_user', ['user_id' => $target->id, 'accommodation_id' => $a2->id]);
    }

    public function test_cambiar_de_cuenta_limpia_el_acotamiento(): void
    {
        Sanctum::actingAs($this->platform);

        $a1 = $this->makeAccommodation($this->account);
        $target = $this->makeUser(User::TYPE_ACCOUNT);
        $target->accommodations()->sync([$a1->id]);

        $destino = Account::create(['name' => 'Cuenta destino '.uniqid()]);

        // El PATCH no toca accommodation_ids, pero el pivote apuntaba a un
        // alojamiento de la cuenta anterior —otro tenant— y hay que limpiarlo.
        $this->patchJson("/api/admin/v1/users/{$target->id}", [
            'account_id' => $destino->id,
        ])->assertOk();

        $this->assertDatabaseMissing('accommodation_user', ['user_id' => $target->id]);
    }

    public function test_degradar_un_account_a_platform_limpia_el_acotamiento(): void
    {
        Sanctum::actingAs($this->platform);

        $a1 = $this->makeAccommodation($this->account);
        $target = $this->makeUser(User::TYPE_ACCOUNT);
        $target->accommodations()->sync([$a1->id]);

        // Solo `account` lleva acotamiento: dejar de serlo lo limpia.
        $this->patchJson("/api/admin/v1/users/{$target->id}", [
            'user_type' => User::TYPE_PLATFORM,
        ])->assertOk();

        $this->assertDatabaseMissing('accommodation_user', ['user_id' => $target->id]);
    }

    // --- Resource -----------------------------------------------------------

    public function test_el_resource_no_expone_password_ni_tokens(): void
    {
        Sanctum::actingAs($this->platform);

        $target = $this->makeUser(User::TYPE_ACCOUNT);

        $response = $this->getJson("/api/admin/v1/users/{$target->id}")->assertOk();

        $data = $response->json('data');

        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('remember_token', $data);
        $this->assertArrayNotHasKey('tokens', $data);
        $this->assertSame(
            ['id', 'name', 'email', 'user_type', 'account_id', 'client_id', 'accommodations', 'created_at'],
            array_keys($data)
        );
    }

    // --- Listado y filtros --------------------------------------------------

    public function test_el_listado_filtra_por_tipo_y_busca_por_nombre_o_email(): void
    {
        Sanctum::actingAs($this->platform);

        $target = $this->makeUser(User::TYPE_ACCOUNT, [
            'name' => 'Buscable Rodriguez',
            'email' => 'buscable-'.uniqid().'@test.local',
        ]);

        $porNombre = $this->getJson('/api/admin/v1/users?search=Buscable')->assertOk();
        $this->assertContains($target->id, array_column($porNombre->json('data'), 'id'));

        $porEmail = $this->getJson('/api/admin/v1/users?search='.$target->email)->assertOk();
        $this->assertContains($target->id, array_column($porEmail->json('data'), 'id'));

        $porTipo = $this->getJson('/api/admin/v1/users?user_type='.User::TYPE_CLIENT)->assertOk();
        $this->assertNotContains($target->id, array_column($porTipo->json('data'), 'id'));
    }

    // --- Baja ---------------------------------------------------------------

    public function test_la_baja_es_logica_y_revoca_los_tokens(): void
    {
        Sanctum::actingAs($this->platform);

        $target = $this->makeUser(User::TYPE_ACCOUNT);
        $target->createToken('test-token');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $target->id,
            'tokenable_type' => User::class,
        ]);

        $this->deleteJson("/api/admin/v1/users/{$target->id}")->assertNoContent();

        // Soft delete: la fila sigue, pero el usuario deja de existir para la app.
        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertNull(User::find($target->id));

        // Sin esto la baja sería cosmética: el token seguiría autenticando.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $target->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_un_usuario_no_puede_darse_de_baja_a_si_mismo(): void
    {
        Sanctum::actingAs($this->platform);

        $this->deleteJson("/api/admin/v1/users/{$this->platform->id}")->assertForbidden();

        $this->assertNotSoftDeleted('users', ['id' => $this->platform->id]);
    }

    public function test_no_se_puede_degradar_al_ultimo_platform(): void
    {
        // Camino que el guard de borrado no cubre: un platform puede degradarse
        // a sí mismo con un PATCH y dejar la plataforma sin administradores.
        // Se aísla dejando un único platform (la transacción revierte al final).
        User::where('user_type', User::TYPE_PLATFORM)
            ->whereKeyNot($this->platform->id)
            ->delete();

        Sanctum::actingAs($this->platform);

        $this->patchJson("/api/admin/v1/users/{$this->platform->id}", [
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $this->account->id,
        ])->assertStatus(422)->assertJsonValidationErrors('user_type');

        $this->assertDatabaseHas('users', [
            'id' => $this->platform->id,
            'user_type' => User::TYPE_PLATFORM,
        ]);
    }

    public function test_se_puede_degradar_un_platform_si_queda_otro(): void
    {
        $otro = $this->makeUser(User::TYPE_PLATFORM);

        Sanctum::actingAs($this->platform);

        $this->patchJson("/api/admin/v1/users/{$otro->id}", [
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $this->account->id,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $otro->id,
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $this->account->id,
        ]);
    }
}
