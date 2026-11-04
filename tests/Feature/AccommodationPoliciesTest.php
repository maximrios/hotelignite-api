<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationPolicy;
use App\Models\AccommodationRatePolicy;
use App\Models\Account;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pestaña Políticas del PMS. Antes se mandaba al PATCH de la ficha, que lo
 * descartaba en silencio y contestaba 200.
 */
class AccommodationPoliciesTest extends TestCase
{
    use DatabaseTransactions;

    private Account $account;

    private Accommodation $acc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::create(['name' => 'Cuenta test']);
        $this->acc = $this->accommodation($this->account);
    }

    #[Test]
    public function guarda_y_devuelve_las_politicas(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $this->putJson($this->url(), [
            'checkin_from' => '14:00',
            'checkin_to' => '',
            'checkout_from' => '08:00',
            'checkout_to' => '10:30',
            'min_age' => 18,
            'allow_children' => true,
            'children_max_age' => 12,
            'allow_pets' => true,
            'allow_smoking' => false,
            'allow_parties' => false,
            'payment_card' => true,
            'payment_cash' => true,
            'payment_transfer' => false,
            'payment_crypto' => false,
            'house_rules' => 'Silencio después de las 23.',
            'rate_policies' => [
                'flexible' => ['cancel_days' => 3, 'cancel_penalty' => 50, 'no_show_penalty' => 100],
                'moderate' => ['cancel_days' => null, 'cancel_penalty' => null, 'no_show_penalty' => null],
                'strict' => ['cancel_days' => 15, 'cancel_penalty' => 100, 'no_show_penalty' => 100],
                'non_refundable' => ['cancel_days' => null, 'cancel_penalty' => 100, 'no_show_penalty' => 100],
            ],
        ])->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.checkin_from', '14:00')
            ->assertJsonPath('data.checkin_to', null)
            ->assertJsonPath('data.checkout_to', '10:30')
            ->assertJsonPath('data.allow_pets', true)
            ->assertJsonPath('data.house_rules', 'Silencio después de las 23.')
            ->assertJsonPath('data.rate_policies.flexible.cancel_days', 3)
            ->assertJsonPath('data.rate_policies.moderate', null)
            ->assertJsonPath('data.rate_policies.strict.cancel_days', 15);

        $this->assertSame(1, AccommodationPolicy::where('accommodation_id', $this->acc->id)->count());
        $this->assertEqualsCanonicalizing(
            ['flexible', 'strict', 'non_refundable'],
            AccommodationRatePolicy::where('accommodation_id', $this->acc->id)->pluck('rate_type')->all(),
        );

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.min_age', 18);
    }

    #[Test]
    public function vaciar_un_tipo_de_tarifa_borra_la_regla(): void
    {
        Sanctum::actingAs($this->owner($this->account));
        AccommodationRatePolicy::create(['accommodation_id' => $this->acc->id, 'rate_type' => 'flexible', 'cancel_days' => 2]);

        $this->putJson($this->url(), [
            'rate_policies' => ['flexible' => ['cancel_days' => null, 'cancel_penalty' => null, 'no_show_penalty' => null]],
        ])->assertOk()->assertJsonPath('data.rate_policies.flexible', null);

        $this->assertFalse($this->acc->ratePolicies()->exists());
    }

    #[Test]
    public function sin_politicas_devuelve_vacio(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.allow_pets', false)
            ->assertJsonPath('data.checkin_from', null);
    }

    #[Test]
    public function valida_horas_y_porcentajes(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $this->putJson($this->url(), [
            'checkin_from' => '25:00',
            'rate_policies' => ['flexible' => ['cancel_penalty' => 150]],
        ])->assertStatus(422)->assertJsonValidationErrors(['checkin_from', 'rate_policies.flexible.cancel_penalty']);
    }

    #[Test]
    public function otra_cuenta_no_lee_ni_escribe(): void
    {
        Sanctum::actingAs($this->owner(Account::create(['name' => 'Otra'])));

        $this->getJson($this->url())->assertForbidden();
        $this->putJson($this->url(), ['allow_pets' => true])->assertForbidden();
        $this->assertFalse(AccommodationPolicy::where('accommodation_id', $this->acc->id)->exists());
    }

    #[Test]
    public function otra_cuenta_no_toca_servicios_ni_imagenes(): void
    {
        Sanctum::actingAs($this->owner(Account::create(['name' => 'Otra'])));
        $base = "/api/admin/v1/accommodations/{$this->acc->id}";

        $this->getJson("{$base}/services")->assertForbidden();
        $this->putJson("{$base}/services", ['service_ids' => [$this->serviceId()]])->assertForbidden();
        $this->getJson("{$base}/images")->assertForbidden();
        $this->deleteJson("{$base}/services/{$this->serviceId()}")->assertForbidden();
    }

    #[Test]
    public function la_cuenta_duena_sigue_gestionando_sus_servicios(): void
    {
        Sanctum::actingAs($this->owner($this->account));

        $this->putJson("/api/admin/v1/accommodations/{$this->acc->id}/services", ['service_ids' => [$this->serviceId()]])->assertOk();
        $this->getJson("/api/admin/v1/accommodations/{$this->acc->id}/images")->assertOk();
    }

    private function serviceId(): int
    {
        $id = Service::query()->value('id');
        if ($id === null) {
            $this->markTestSkipped('La base de tests no tiene servicios.');
        }

        return (int) $id;
    }

    private function url(): string
    {
        return "/api/admin/v1/accommodations/{$this->acc->id}/policies";
    }

    private function accommodation(Account $account): Accommodation
    {
        return Accommodation::create([
            'account_id' => $account->id,
            'name' => 'Hotel Bonarda',
            'slug' => 'hotel-bonarda-'.uniqid(),
            'enabled' => 1,
        ]);
    }

    private function owner(Account $account): User
    {
        return User::create([
            'name' => 'user '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $account->id,
        ]);
    }
}
