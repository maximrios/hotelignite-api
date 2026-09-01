<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifica el límite de tenencia sobre Accommodation (Bloque B del hardening).
 *
 * Usa DatabaseTransactions (no RefreshDatabase) porque el schema real viene de
 * un dump y no hay migración que cree `accommodations` — las filas se crean
 * dentro de una transacción que se revierte al terminar cada test.
 */
class AccommodationTenancyTest extends TestCase
{
    use DatabaseTransactions;

    private Account $accountA;

    private Account $accountB;

    private Accommodation $accA;

    private Accommodation $accB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountA = Account::create(['name' => 'Cuenta A test']);
        $this->accountB = Account::create(['name' => 'Cuenta B test']);

        $cityId = $this->anyCityId();

        $this->accA = Accommodation::create([
            'account_id' => $this->accountA->id,
            'city_id' => $cityId,
            'name' => 'Hotel A test',
            'slug' => 'hotel-a-test-'.uniqid(),
        ]);
        $this->accB = Accommodation::create([
            'account_id' => $this->accountB->id,
            'city_id' => $cityId,
            'name' => 'Hotel B test',
            'slug' => 'hotel-b-test-'.uniqid(),
        ]);
    }

    /**
     * accommodations.city_id es NOT NULL sin default en el schema real, y la
     * tabla legacy `cities` no tiene timestamps (por eso el insert crudo).
     */
    private function anyCityId(): int
    {
        return City::query()->value('id')
            ?? DB::table('cities')->insertGetId([
                'name' => 'Ciudad test',
                'slug' => 'ciudad-test-'.uniqid(),
            ]);
    }

    private function accountUser(Account $account): User
    {
        return User::create([
            'name' => 'user '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $account->id,
        ]);
    }

    #[Test]
    public function account_user_solo_ve_sus_accommodations_en_el_listado(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $ids = collect($this->getJson('/api/admin/v1/accommodations')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($this->accA->id), 'Debe ver el suyo');
        $this->assertFalse($ids->contains($this->accB->id), 'No debe ver el de otra cuenta');
    }

    #[Test]
    public function account_user_acotado_solo_ve_los_alojamientos_de_su_pivote(): void
    {
        // Segundo alojamiento en la MISMA cuenta A, para probar el narrowing
        // dentro de la cuenta (no entre cuentas).
        $accA2 = Accommodation::create([
            'account_id' => $this->accountA->id,
            'city_id' => $this->anyCityId(),
            'name' => 'Hotel A2 test',
            'slug' => 'hotel-a2-test-'.uniqid(),
        ]);

        $user = $this->accountUser($this->accountA);
        $user->accommodations()->sync([$this->accA->id]);

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/admin/v1/accommodations')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($this->accA->id), 'Ve el alojamiento al que está acotado');
        $this->assertFalse($ids->contains($accA2->id), 'No ve el otro de su cuenta: está acotado');
        $this->assertFalse($ids->contains($this->accB->id), 'Nunca ve el de otra cuenta');
    }

    #[Test]
    public function account_user_no_puede_ver_accommodation_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->getJson("/api/admin/v1/accommodations/{$this->accB->id}")->assertForbidden();
    }

    #[Test]
    public function account_user_acotado_no_puede_ver_ni_editar_otro_de_su_cuenta(): void
    {
        // Sin este corte el acotamiento sería solo cosmético: se ocultaría en el
        // listado pero seguiría accesible por id. Es control de acceso, no un filtro.
        $accA2 = Accommodation::create([
            'account_id' => $this->accountA->id,
            'city_id' => $this->anyCityId(),
            'name' => 'Hotel A2 test',
            'slug' => 'hotel-a2-test-'.uniqid(),
        ]);

        $user = $this->accountUser($this->accountA);
        $user->accommodations()->sync([$this->accA->id]);

        Sanctum::actingAs($user);

        $this->getJson("/api/admin/v1/accommodations/{$accA2->id}")->assertForbidden();
        $this->patchJson("/api/admin/v1/accommodations/{$accA2->id}", ['name' => 'Nope'])
            ->assertForbidden();

        // El que sí tiene acotado sigue accesible.
        $this->getJson("/api/admin/v1/accommodations/{$this->accA->id}")->assertOk();
    }

    #[Test]
    public function account_user_no_puede_editar_accommodation_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->putJson("/api/admin/v1/accommodations/{$this->accB->id}", ['name' => 'Hackeado'])
            ->assertForbidden();

        $this->assertDatabaseHas('accommodations', ['id' => $this->accB->id, 'name' => 'Hotel B test']);
    }

    #[Test]
    public function account_user_no_puede_borrar_accommodation_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->deleteJson("/api/admin/v1/accommodations/{$this->accB->id}")->assertForbidden();
        $this->assertDatabaseHas('accommodations', ['id' => $this->accB->id]);
    }

    #[Test]
    public function account_user_puede_editar_su_propio_accommodation(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->putJson("/api/admin/v1/accommodations/{$this->accA->id}", ['name' => 'Hotel A editado'])
            ->assertOk();

        $this->assertDatabaseHas('accommodations', ['id' => $this->accA->id, 'name' => 'Hotel A editado']);
    }

    #[Test]
    public function account_user_no_puede_reasignar_su_accommodation_a_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->putJson("/api/admin/v1/accommodations/{$this->accA->id}", [
            'name' => 'Hotel A',
            'account_id' => $this->accountB->id,
        ])->assertOk();

        // El account_id debe seguir siendo el de la cuenta A.
        $this->assertDatabaseHas('accommodations', [
            'id' => $this->accA->id,
            'account_id' => $this->accountA->id,
        ]);
    }

    #[Test]
    public function account_user_crea_en_su_propia_cuenta_aunque_pida_otra(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $res = $this->postJson('/api/admin/v1/accommodations', [
            'name' => 'Nuevo hotel',
            'city_id' => $this->anyCityId(),
            'account_id' => $this->accountB->id, // intento de crear en otra cuenta
        ])->assertSuccessful();

        $this->assertDatabaseHas('accommodations', [
            'id' => $res->json('data.id'),
            'account_id' => $this->accountA->id,
        ]);
    }

    #[Test]
    public function client_user_no_puede_escribir(): void
    {
        $client = Client::create(['name' => 'Agencia test']);
        $user = User::create([
            'name' => 'client user',
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/admin/v1/accommodations', ['name' => 'X'])->assertForbidden();
    }

    #[Test]
    public function client_user_solo_ve_accommodations_relacionados(): void
    {
        $client = Client::create(['name' => 'Agencia test']);
        $client->accommodations()->attach($this->accA->id);

        $user = User::create([
            'name' => 'client user',
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]);
        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/admin/v1/accommodations')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($this->accA->id), 'Ve el relacionado');
        $this->assertFalse($ids->contains($this->accB->id), 'No ve el no relacionado');
    }

    #[Test]
    public function platform_user_ve_todo(): void
    {
        $user = User::create([
            'name' => 'platform user',
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_PLATFORM,
        ]);
        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/admin/v1/accommodations?limit=200')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($this->accA->id));
        $this->assertTrue($ids->contains($this->accB->id));
    }
}
