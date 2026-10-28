<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Planes del PMS (Free → Premium), límites, cambio de plan y aceptaciones legales. */
class AccommodationBillingTest extends TestCase
{
    use DatabaseTransactions;

    private Accommodation $acc;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);
        $account = Account::create(['name' => 'Cuenta planes test']);
        $this->acc = Accommodation::create([
            'account_id' => $account->id, 'city_id' => $cityId,
            'name' => 'Hotel planes test', 'slug' => 'hotel-planes-test-'.uniqid(),
        ]);
        $this->owner = User::create([
            'name' => 'owner '.uniqid(), 'email' => uniqid().'@test.com', 'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT, 'account_id' => $account->id,
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function planId(string $slug): int
    {
        return (int) Plan::where('slug', $slug)->value('id');
    }

    private function type(): int
    {
        return DB::table('room_types')->insertGetId([
            'accommodation_id' => $this->acc->id, 'code' => 'DBL'.random_int(10, 99), 'standard_occupancy' => 2,
            'max_occupancy' => 2, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function todo_alojamiento_nace_en_free_con_sus_limites(): void
    {
        $this->assertSame($this->planId('free'), (int) $this->acc->plan_id);

        $type = $this->type();
        $numbers = array_map(fn ($n) => (string) (100 + $n), range(1, 11));
        $this->postJson('/api/v1/inventory/rooms', ['room_type_id' => $type, 'numbers' => $numbers])
            ->assertUnprocessable()
            ->assertJsonPath('errors.numbers.0', fn (string $m) => str_contains($m, 'hasta 10 habitaciones'));
        $this->postJson('/api/v1/inventory/rooms', ['room_type_id' => $type, 'numbers' => array_slice($numbers, 0, 10)])->assertCreated();

        // Free: sin housekeeping ni planes de tarifa (la tarifa base sí).
        $this->getJson('/api/v1/housekeeping?accommodation_id='.$this->acc->id)->assertForbidden();
        $this->postJson('/api/v1/pricing/plans', [
            'accommodation_id' => $this->acc->id, 'name' => 'NR', 'code' => 'NR', 'cancellation' => 'non_refundable',
            'meal_plan' => 'ro', 'adjustment_type' => 'percent', 'adjustment_value' => -10, 'room_type_ids' => [$type],
        ])->assertForbidden();
        $this->getJson("/api/v1/pricing/grid?accommodation_id={$this->acc->id}&from=2026-11-01&to=2026-11-08")->assertOk();

        $this->getJson('/api/v1/billing?accommodation_id='.$this->acc->id)
            ->assertOk()
            ->assertJsonPath('plan.slug', 'free')
            ->assertJsonPath('usage.rooms', ['used' => 10, 'limit' => 10])
            ->assertJsonPath('plans.0.slug', 'free')
            ->assertJsonPath('plans.3.coming_soon', true)
            ->assertJsonPath('plans.1.price_monthly', 25000)
            ->assertJsonPath('plans.1.price_yearly', 240000)
            ->assertJsonPath('invoices', []);
    }

    #[Test]
    public function el_cambio_de_plan_se_solicita_y_lo_aprueba_el_equipo(): void
    {
        $this->postJson('/api/v1/billing/plan-requests', ['accommodation_id' => $this->acc->id, 'plan' => 'enterprise', 'billing_period' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plan']);

        $id = $this->postJson('/api/v1/billing/plan-requests', ['accommodation_id' => $this->acc->id, 'plan' => 'pro', 'billing_period' => 'yearly'])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');

        // Uno pendiente por vez; se puede cancelar.
        $this->postJson('/api/v1/billing/plan-requests', ['accommodation_id' => $this->acc->id, 'plan' => 'starter', 'billing_period' => 'monthly'])
            ->assertStatus(409);
        $this->getJson('/api/v1/billing?accommodation_id='.$this->acc->id)->assertJsonPath('pending_request.plan.slug', 'pro');

        // El hotelero no puede aprobarse solo.
        $this->postJson("/api/admin/v1/plan-requests/{$id}/approve")->assertForbidden();

        Sanctum::actingAs(User::create([
            'name' => 'staff '.uniqid(), 'email' => uniqid().'@test.com', 'password' => bcrypt('secret'),
            'user_type' => User::TYPE_PLATFORM,
        ]));
        $this->getJson('/api/admin/v1/plan-requests')->assertOk()->assertJsonFragment(['id' => $id]);
        $this->postJson("/api/admin/v1/plan-requests/{$id}/approve", ['payment_method' => 'transfer'])->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame($this->planId('pro'), (int) $this->acc->fresh()->plan_id);
        $this->assertDatabaseHas('subscriptions', [
            'subscriber_id' => $this->acc->id, 'status' => 'active', 'billing_period' => 'yearly', 'price' => 52800000,
        ]);

        // Pro: créditos de IA del mes, una sola vez por período.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/billing?accommodation_id='.$this->acc->id)
            ->assertJsonPath('plan.slug', 'pro')
            ->assertJsonPath('usage.ai_credits.balance', 500)
            ->assertJsonPath('subscription.status', 'active');
        $this->getJson('/api/v1/billing?accommodation_id='.$this->acc->id)->assertJsonPath('usage.ai_credits.granted', 500);
        $this->getJson('/api/v1/housekeeping?accommodation_id='.$this->acc->id)->assertOk();
    }

    #[Test]
    public function bajar_de_plan_con_mas_habitaciones_pide_confirmacion(): void
    {
        $this->acc->update(['plan_id' => $this->planId('starter')]);
        $type = $this->type();
        $this->postJson('/api/v1/inventory/rooms', [
            'room_type_id' => $type, 'numbers' => array_map(fn ($n) => (string) (200 + $n), range(1, 12)),
        ])->assertCreated();

        $this->postJson('/api/v1/billing/plan-requests', ['accommodation_id' => $this->acc->id, 'plan' => 'free', 'billing_period' => 'monthly'])
            ->assertUnprocessable()->assertJsonPath('errors.plan.0', fn (string $m) => str_contains($m, 'no se borra nada'));
        $this->postJson('/api/v1/billing/plan-requests', [
            'accommodation_id' => $this->acc->id, 'plan' => 'free', 'billing_period' => 'monthly', 'confirm_over_limit' => true,
        ])->assertCreated()->assertJsonPath('billing_period', 'free');
    }

    #[Test]
    public function las_condiciones_se_aceptan_todas_y_quedan_registradas(): void
    {
        $this->getJson('/api/v1/legal/status')->assertOk()
            ->assertJsonPath('all_accepted', false)
            ->assertJsonCount(3, 'documents');

        $this->postJson('/api/v1/legal/accept', ['documents' => ['terms', 'privacy']])
            ->assertUnprocessable()->assertJsonValidationErrors(['documents']);

        $this->postJson('/api/v1/legal/accept', ['documents' => ['terms', 'privacy', 'data_usage']], ['User-Agent' => 'QA'])
            ->assertOk()->assertJsonPath('all_accepted', true);

        $this->assertDatabaseHas('legal_acceptances', [
            'user_id' => $this->owner->id, 'document' => 'data_usage', 'version' => config('legal.documents.data_usage.version'), 'user_agent' => 'QA',
        ]);

        // Una versión nueva vuelve a pedirse.
        config(['legal.documents.terms.version' => '2099-01-01']);
        $this->getJson('/api/v1/legal/status')->assertJsonPath('all_accepted', false);
    }

    #[Test]
    public function no_toca_la_facturacion_de_otro_alojamiento(): void
    {
        $other = Accommodation::create([
            'account_id' => Account::create(['name' => 'Otra'])->id, 'city_id' => $this->acc->city_id,
            'name' => 'Ajeno', 'slug' => 'ajeno-'.uniqid(),
        ]);
        $this->getJson('/api/v1/billing?accommodation_id='.$other->id)->assertNotFound();
        $this->postJson('/api/v1/billing/plan-requests', ['accommodation_id' => $other->id, 'plan' => 'pro', 'billing_period' => 'monthly'])
            ->assertNotFound();
    }
}
