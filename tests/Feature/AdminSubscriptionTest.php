<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Client;
use App\Models\Plan;
use App\Models\PlanChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Suscripciones en el CRM: listado, resumen, ficha y cambio de plan hecho por el equipo. */
class AdminSubscriptionTest extends TestCase
{
    use DatabaseTransactions;

    private Accommodation $acc;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);
        $account = Account::create(['name' => 'Cuenta suscripciones test']);
        $this->acc = Accommodation::create([
            'account_id' => $account->id, 'city_id' => $cityId,
            'name' => 'Hotel suscripciones '.uniqid(), 'slug' => 'hotel-subs-test-'.uniqid(),
        ]);
        $this->owner = User::create([
            'name' => 'owner '.uniqid(), 'email' => uniqid().'@test.com', 'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT, 'account_id' => $account->id,
        ]);
        $this->staff = User::create([
            'name' => 'staff '.uniqid(), 'email' => uniqid().'@test.com', 'password' => bcrypt('secret'),
            'user_type' => User::TYPE_PLATFORM,
        ]);
    }

    private function planId(string $slug): int
    {
        return (int) Plan::where('slug', $slug)->value('id');
    }

    #[Test]
    public function el_hotelero_no_puede_cambiarse_el_plan_por_la_ficha(): void
    {
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/admin/v1/accommodations/{$this->acc->id}", ['plan_id' => $this->planId('enterprise')])->assertOk();
        $this->putJson("/api/admin/v1/accommodations/{$this->acc->id}", ['name' => $this->acc->name, 'plan_id' => $this->planId('enterprise')]);

        $this->assertSame($this->planId('free'), (int) $this->acc->fresh()->plan_id);
        $this->getJson('/api/admin/v1/subscriptions')->assertForbidden();
        $this->putJson("/api/admin/v1/accommodations/{$this->acc->id}/plan", [
            'plan_id' => $this->planId('pro'), 'billing_period' => 'monthly', 'payment_method' => 'manual',
        ])->assertForbidden();
    }

    #[Test]
    public function el_equipo_asigna_un_plan_y_queda_la_suscripcion(): void
    {
        Sanctum::actingAs($this->staff);
        $before = $this->getJson('/api/admin/v1/subscriptions/summary')->assertOk()->json('data');

        $this->putJson("/api/admin/v1/accommodations/{$this->acc->id}/plan", [
            'plan_id' => $this->planId('pro'), 'billing_period' => 'monthly', 'payment_method' => 'manual',
        ])->assertOk()
            ->assertJsonPath('data.plan.slug', 'pro')
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.payment_method', 'manual');

        $this->assertSame($this->planId('pro'), (int) $this->acc->fresh()->plan_id);

        // Mismo plan otra vez: nada que hacer.
        $this->putJson("/api/admin/v1/accommodations/{$this->acc->id}/plan", [
            'plan_id' => $this->planId('pro'), 'billing_period' => 'monthly', 'payment_method' => 'manual',
        ])->assertUnprocessable();

        // Un plan de client no se le asigna a un hotel.
        $this->putJson("/api/admin/v1/accommodations/{$this->acc->id}/plan", [
            'plan_id' => $this->planId('client-padron'), 'billing_period' => 'monthly', 'payment_method' => 'manual',
        ])->assertUnprocessable()->assertJsonValidationErrors(['plan_id']);

        // Lo manual cuenta como activa pero no como ingreso.
        $after = $this->getJson('/api/admin/v1/subscriptions/summary')->json('data');
        $this->assertSame($before['accommodations']['by_status']['active'] + 1, $after['accommodations']['by_status']['active']);
        $this->assertSame($before['mrr']['amount'], $after['mrr']['amount']);

        // Pasar a transferencia anual: cancela la manual y suma al ingreso (anual / 12).
        $this->putJson("/api/admin/v1/accommodations/{$this->acc->id}/plan", [
            'plan_id' => $this->planId('starter'), 'billing_period' => 'yearly', 'payment_method' => 'transfer',
        ])->assertOk()->assertJsonPath('data.subscription.billing_period', 'yearly');
        $yearly = (int) DB::table('subscriptions')->where('subscriber_id', $this->acc->id)
            ->where('subscriber_type', Accommodation::class)->where('status', 'active')->value('price');
        $this->assertSame($before['mrr']['amount'] + intdiv($yearly, 12), $this->getJson('/api/admin/v1/subscriptions/summary')->json('data.mrr.amount'));

        $this->getJson("/api/admin/v1/accommodations/{$this->acc->id}/subscription")
            ->assertOk()
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.1.status', 'canceled');

        $this->getJson('/api/admin/v1/subscriptions?search='.urlencode($this->acc->name))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subscriber.type', 'accommodation')
            ->assertJsonPath('data.0.subscription.payment_method', 'transfer');

        // Bajar al Free: sin suscripción viva, sale del listado por default y aparece en "none".
        $this->putJson("/api/admin/v1/accommodations/{$this->acc->id}/plan", [
            'plan_id' => $this->planId('free'), 'billing_period' => 'monthly', 'payment_method' => 'manual',
        ])->assertOk()->assertJsonPath('data.subscription', null);
        $this->getJson('/api/admin/v1/subscriptions?search='.urlencode($this->acc->name))->assertJsonPath('meta.total', 0);
        $this->getJson('/api/admin/v1/subscriptions?status=none&search='.urlencode($this->acc->name))->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function con_un_pedido_pendiente_el_equipo_no_pisa_el_plan(): void
    {
        PlanChangeRequest::create([
            'accommodation_id' => $this->acc->id, 'from_plan_id' => $this->planId('free'), 'to_plan_id' => $this->planId('pro'),
            'billing_period' => 'monthly', 'status' => PlanChangeRequest::PENDING, 'requested_by_user_id' => $this->owner->id,
        ]);
        Sanctum::actingAs($this->staff);

        $this->putJson("/api/admin/v1/accommodations/{$this->acc->id}/plan", [
            'plan_id' => $this->planId('starter'), 'billing_period' => 'monthly', 'payment_method' => 'manual',
        ])->assertStatus(409);
        $this->getJson("/api/admin/v1/accommodations/{$this->acc->id}/subscription")
            ->assertJsonPath('data.pending_request.plan.slug', 'pro');
    }

    #[Test]
    public function los_clients_se_listan_aunque_no_tengan_suscripcion(): void
    {
        $client = Client::create(['name' => 'Municipio subs '.uniqid(), 'type' => Client::TYPE_GOVERNMENT]);
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/admin/v1/subscriptions?subscriber=client&search='.urlencode($client->name))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subscriber.client_type', 'government')
            ->assertJsonPath('data.0.subscription', null);

        $this->getJson("/api/admin/v1/clients/{$client->id}/subscription")
            ->assertOk()
            ->assertJsonPath('data.subscriber.type', 'client')
            ->assertJsonPath('data.history', []);
    }

    #[Test]
    public function el_backfill_crea_la_suscripcion_que_falta(): void
    {
        $this->acc->forceFill(['plan_id' => $this->planId('pro')])->save();

        $this->artisan('billing:backfill-subscriptions')->assertSuccessful();

        $this->assertDatabaseHas('subscriptions', [
            'subscriber_type' => Accommodation::class, 'subscriber_id' => $this->acc->id,
            'plan_id' => $this->planId('pro'), 'status' => 'active', 'payment_method' => 'manual',
        ]);
        $this->artisan('billing:backfill-subscriptions')->expectsOutput('No hay alojamientos con plan pago sin suscripción.');
    }
}
