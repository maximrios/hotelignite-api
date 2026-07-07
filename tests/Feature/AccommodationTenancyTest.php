<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
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

        $this->accA = Accommodation::create([
            'account_id' => $this->accountA->id,
            'name' => 'Hotel A test',
            'slug' => 'hotel-a-test-'.uniqid(),
        ]);
        $this->accB = Accommodation::create([
            'account_id' => $this->accountB->id,
            'name' => 'Hotel B test',
            'slug' => 'hotel-b-test-'.uniqid(),
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

    /** @test */
    public function account_user_solo_ve_sus_accommodations_en_el_listado(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $ids = collect($this->getJson('/api/admin/v1/accommodations')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($this->accA->id), 'Debe ver el suyo');
        $this->assertFalse($ids->contains($this->accB->id), 'No debe ver el de otra cuenta');
    }

    /** @test */
    public function account_user_no_puede_ver_accommodation_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->getJson("/api/admin/v1/accommodations/{$this->accB->id}")->assertForbidden();
    }

    /** @test */
    public function account_user_no_puede_editar_accommodation_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->putJson("/api/admin/v1/accommodations/{$this->accB->id}", ['name' => 'Hackeado'])
            ->assertForbidden();

        $this->assertDatabaseHas('accommodations', ['id' => $this->accB->id, 'name' => 'Hotel B test']);
    }

    /** @test */
    public function account_user_no_puede_borrar_accommodation_de_otra_cuenta(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->deleteJson("/api/admin/v1/accommodations/{$this->accB->id}")->assertForbidden();
        $this->assertDatabaseHas('accommodations', ['id' => $this->accB->id]);
    }

    /** @test */
    public function account_user_puede_editar_su_propio_accommodation(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $this->putJson("/api/admin/v1/accommodations/{$this->accA->id}", ['name' => 'Hotel A editado'])
            ->assertOk();

        $this->assertDatabaseHas('accommodations', ['id' => $this->accA->id, 'name' => 'Hotel A editado']);
    }

    /** @test */
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

    /** @test */
    public function account_user_crea_en_su_propia_cuenta_aunque_pida_otra(): void
    {
        Sanctum::actingAs($this->accountUser($this->accountA));

        $res = $this->postJson('/api/admin/v1/accommodations', [
            'name' => 'Nuevo hotel',
            'account_id' => $this->accountB->id, // intento de crear en otra cuenta
        ])->assertSuccessful();

        $this->assertDatabaseHas('accommodations', [
            'id' => $res->json('data.id'),
            'account_id' => $this->accountA->id,
        ]);
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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
