<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD del catálogo de servicios bajo /api/admin/v1/services
 * (docs/services-admin-crud-plan.md).
 *
 * Dos cosas que se testean acá y no son obvias:
 *
 * - `services` es un catálogo **global**, no cuelga de ninguna cuenta: leerlo lo
 *   puede cualquier usuario del panel, escribirlo solo `platform`.
 * - La tabla no tiene `slug` ni `icon` ni timestamps; `icon` es un alias de la
 *   columna `ico`. Varios tests fijan ese mapeo para que no vuelva a divergir.
 *
 * Usa DatabaseTransactions (no RefreshDatabase) porque el schema viene de un
 * dump con datos reales: RefreshDatabase haría migrate:fresh y lo borraría.
 */
class ServiceCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = $this->makeUser(User::TYPE_PLATFORM);
    }

    private function makeUser(string $type): User
    {
        $account = $type === User::TYPE_ACCOUNT
            ? Account::create(['name' => 'Cuenta test services'])
            : null;

        return User::create([
            'name' => 'Test '.$type,
            'email' => $type.'-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => $type,
            'account_id' => $account?->id,
        ]);
    }

    private function makeService(array $overrides = []): Service
    {
        return Service::create(array_merge([
            'name' => 'Servicio '.uniqid(),
            'type' => 'general',
            'icon' => null,
            'is_highlighted' => false,
            'enabled' => true,
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Wifi '.uniqid(),
            'type' => 'general',
            'icon' => null,
            'is_highlighted' => false,
            'enabled' => true,
        ], $overrides);
    }

    /** accommodations.city_id es NOT NULL sin default en el schema real. */
    private function makeAccommodation(): Accommodation
    {
        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId([
                'name' => 'Ciudad test',
                'slug' => 'ciudad-test-'.uniqid(),
            ]);

        return Accommodation::create([
            'account_id' => Account::create(['name' => 'Cuenta aloj services'])->id,
            'city_id' => $cityId,
            'name' => 'Aloj '.uniqid(),
            'slug' => 'aloj-'.uniqid(),
        ]);
    }

    // --- Acceso -------------------------------------------------------------

    public function test_un_account_puede_leer_el_catalogo(): void
    {
        $service = $this->makeService();
        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT));

        $this->getJson('/api/admin/v1/services')->assertOk();
        $this->getJson("/api/admin/v1/services/{$service->id}")->assertOk();
    }

    public function test_un_account_no_puede_escribir_el_catalogo(): void
    {
        $service = $this->makeService();
        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT));

        $this->postJson('/api/admin/v1/services', $this->payload())->assertForbidden();
        $this->patchJson("/api/admin/v1/services/{$service->id}", ['name' => 'Otro nombre'])->assertForbidden();
        $this->deleteJson("/api/admin/v1/services/{$service->id}")->assertForbidden();
    }

    public function test_un_client_no_puede_escribir_el_catalogo(): void
    {
        $service = $this->makeService();
        Sanctum::actingAs($this->makeUser(User::TYPE_CLIENT));

        // Cae antes en `client.readonly`, que es 403 igual: los clients son
        // read-only en toda la API.
        $this->postJson('/api/admin/v1/services', $this->payload())->assertForbidden();
        $this->deleteJson("/api/admin/v1/services/{$service->id}")->assertForbidden();
    }

    // --- Alta ---------------------------------------------------------------

    public function test_alta_devuelve_201_con_el_recurso_envuelto_en_data(): void
    {
        Sanctum::actingAs($this->platform);

        $response = $this->postJson('/api/admin/v1/services', $this->payload([
            'name' => 'Wifi de prueba',
            'type' => 'room',
            'icon' => '<i class="fa fa-wifi"></i>',
            'is_highlighted' => true,
        ]))->assertCreated();

        // El CRM lee `res.data.id` para redirigir.
        $response->assertJsonPath('data.name', 'Wifi de prueba')
            ->assertJsonPath('data.type', 'room')
            ->assertJsonPath('data.icon', '<i class="fa fa-wifi"></i>')
            ->assertJsonPath('data.is_highlighted', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.qty', 0);

        $this->assertIsInt($response->json('data.id'));

        // El alias `icon` tiene que haber aterrizado en la columna `ico`.
        $this->assertDatabaseHas('services', [
            'id' => $response->json('data.id'),
            'ico' => '<i class="fa fa-wifi"></i>',
        ]);
    }

    public function test_el_recurso_no_expone_slug(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/services', $this->payload())
            ->assertCreated()
            ->assertJsonMissingPath('data.slug');
    }

    public function test_un_icon_nulo_se_guarda_como_string_vacio(): void
    {
        Sanctum::actingAs($this->platform);

        // `ico` es NOT NULL: si el null del CRM llegara crudo, el INSERT falla.
        $response = $this->postJson('/api/admin/v1/services', $this->payload(['icon' => null]))
            ->assertCreated()
            ->assertJsonPath('data.icon', null);

        $this->assertDatabaseHas('services', [
            'id' => $response->json('data.id'),
            'ico' => '',
        ]);
    }

    public function test_el_ico_legacy_en_cero_se_lee_como_null(): void
    {
        Sanctum::actingAs($this->platform);

        $service = $this->makeService();
        DB::table('services')->where('id', $service->id)->update(['ico' => '0']);

        $this->getJson("/api/admin/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.icon', null);
    }

    public function test_alta_con_nombre_duplicado_falla(): void
    {
        Sanctum::actingAs($this->platform);

        $existente = $this->makeService(['name' => 'Servicio repetido']);

        $this->postJson('/api/admin/v1/services', $this->payload(['name' => $existente->name]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_alta_sin_tipo_falla(): void
    {
        Sanctum::actingAs($this->platform);

        $payload = $this->payload();
        unset($payload['type']);

        $this->postJson('/api/admin/v1/services', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_alta_con_tipo_invalido_falla(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/services', $this->payload(['type' => 'jacuzzi']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    // --- Edición ------------------------------------------------------------

    public function test_edicion_parcial_por_patch(): void
    {
        Sanctum::actingAs($this->platform);

        $service = $this->makeService(['name' => 'Nombre viejo', 'enabled' => true]);

        $this->patchJson("/api/admin/v1/services/{$service->id}", [
            'name' => 'Nombre nuevo',
            'enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Nombre nuevo')
            ->assertJsonPath('data.enabled', false)
            // No se mandó: tiene que quedar como estaba.
            ->assertJsonPath('data.type', 'general');

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'name' => 'Nombre nuevo',
            'enabled' => 0,
        ]);
    }

    public function test_editar_conservando_el_propio_nombre_no_choca_con_el_unique(): void
    {
        Sanctum::actingAs($this->platform);

        $service = $this->makeService(['name' => 'Nombre estable']);

        $this->patchJson("/api/admin/v1/services/{$service->id}", [
            'name' => 'Nombre estable',
            'is_highlighted' => true,
        ])->assertOk()->assertJsonPath('data.is_highlighted', true);
    }

    public function test_editar_con_el_nombre_de_otro_servicio_falla(): void
    {
        Sanctum::actingAs($this->platform);

        $otro = $this->makeService(['name' => 'Servicio ocupado']);
        $service = $this->makeService();

        $this->patchJson("/api/admin/v1/services/{$service->id}", ['name' => $otro->name])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_editar_un_servicio_inexistente_da_404(): void
    {
        Sanctum::actingAs($this->platform);

        $this->patchJson('/api/admin/v1/services/999999', ['name' => 'Cualquiera'])
            ->assertNotFound();
    }

    // --- Baja ---------------------------------------------------------------

    public function test_baja_de_un_servicio_sin_uso_devuelve_204(): void
    {
        Sanctum::actingAs($this->platform);

        $service = $this->makeService();

        $this->deleteJson("/api/admin/v1/services/{$service->id}")->assertNoContent();

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_baja_de_un_servicio_en_uso_devuelve_409_y_no_borra(): void
    {
        Sanctum::actingAs($this->platform);

        $service = $this->makeService();
        $service->accommodations()->attach($this->makeAccommodation()->id);

        // Sin FK con ON DELETE CASCADE, borrarlo dejaría el pivote apuntando a
        // un service_id inexistente.
        $this->deleteJson("/api/admin/v1/services/{$service->id}")->assertStatus(409);

        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }

    // --- Listado ------------------------------------------------------------

    public function test_el_listado_trae_meta_completa_y_qty(): void
    {
        Sanctum::actingAs($this->platform);

        $service = $this->makeService(['name' => 'Zzz filtrable '.uniqid()]);
        $service->accommodations()->attach($this->makeAccommodation()->id);

        $response = $this->getJson('/api/admin/v1/services?name='.urlencode($service->name))
            ->assertOk()
            ->assertJsonPath('data.0.id', $service->id)
            ->assertJsonPath('data.0.qty', 1);

        // `from`/`to` son lo que le falta al CRM para poder paginar la pantalla.
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
        ]);
    }

    public function test_el_listado_filtra_por_enabled(): void
    {
        Sanctum::actingAs($this->platform);

        $apagado = $this->makeService(['enabled' => false]);

        $ids = collect($this->getJson('/api/admin/v1/services?enabled=1&per_page=100')->json('data'))
            ->pluck('id');

        $this->assertNotContains($apagado->id, $ids);
    }
}
