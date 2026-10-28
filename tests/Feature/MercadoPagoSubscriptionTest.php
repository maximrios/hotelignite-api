<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\AccommodationBillingRepository;
use App\Services\MercadoPagoSubscriptions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MercadoPago\Resources\PreApproval;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Cambio de plan pagado con una suscripción (preapproval) de Mercado Pago, con la API simulada. */
class MercadoPagoSubscriptionTest extends TestCase
{
    use DatabaseTransactions;

    private Accommodation $acc;

    protected function setUp(): void
    {
        parent::setUp();

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);
        $account = Account::create(['name' => 'Cuenta MP test']);
        $this->acc = Accommodation::create([
            'account_id' => $account->id, 'city_id' => $cityId,
            'name' => 'Hotel MP test', 'slug' => 'hotel-mp-test-'.uniqid(),
        ]);
        Sanctum::actingAs(User::create([
            'name' => 'owner '.uniqid(), 'email' => uniqid().'@test.com', 'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT, 'account_id' => $account->id,
        ]));
    }

    private function preapproval(string $id, string $status): PreApproval
    {
        $p = new PreApproval;
        $p->id = $id;
        $p->status = $status;
        $p->init_point = "https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id={$id}";

        return $p;
    }

    private function checkout(string $plan, string $period = 'monthly')
    {
        return $this->postJson('/api/v1/billing/subscriptions', [
            'accommodation_id' => $this->acc->id, 'plan' => $plan, 'billing_period' => $period,
        ]);
    }

    #[Test]
    public function el_pago_autorizado_activa_el_plan_con_el_monto_del_catalogo(): void
    {
        $this->mock(MercadoPagoSubscriptions::class, function (MockInterface $mp) {
            $mp->shouldReceive('createPending')->once()
                ->withArgs(fn (array $body) => $body['auto_recurring']['transaction_amount'] === 25000.0
                    && $body['auto_recurring']['frequency'] === 1
                    && $body['auto_recurring']['currency_id'] === 'ARS'
                    && str_starts_with($body['external_reference'], 'hi-pcr-'))
                ->andReturn($this->preapproval('pre-1', 'pending'));
            $mp->shouldReceive('find')->with('pre-1')->andReturn(
                $this->preapproval('pre-1', 'pending'),
                $this->preapproval('pre-1', 'authorized'),
            );
        });

        $url = $this->checkout('starter')->assertCreated()->assertJsonPath('request.payment_method', 'mercadopago')->json('checkout_url');
        $this->assertStringContainsString('pre-1', $url);

        // Volver a "Suscribirme" retoma el mismo link (no crea otro preapproval).
        $this->checkout('starter')->assertCreated()->assertJsonPath('checkout_url', $url);

        // Todavía sin autorizar: sigue pendiente y el plan no cambia.
        $this->postJson('/api/v1/billing/subscriptions/sync', ['accommodation_id' => $this->acc->id])
            ->assertOk()->assertJsonPath('status', 'pending');
        $this->assertSame('free', $this->acc->fresh()->plan->slug);

        $this->postJson('/api/v1/billing/subscriptions/sync', ['accommodation_id' => $this->acc->id])
            ->assertOk()->assertJsonPath('status', 'approved');

        $acc = $this->acc->fresh();
        $this->assertSame('starter', $acc->plan->slug);
        $sub = $acc->subscriptions()->live()->first();
        $this->assertSame(Subscription::METHOD_MERCADOPAGO, $sub->payment_method);
        $this->assertSame('pre-1', $sub->provider_subscription_id);
    }

    #[Test]
    public function el_anual_cobra_cada_doce_meses_con_descuento(): void
    {
        $this->mock(MercadoPagoSubscriptions::class, function (MockInterface $mp) {
            $mp->shouldReceive('createPending')->once()
                ->withArgs(fn (array $body) => $body['auto_recurring']['frequency'] === 12
                    && $body['auto_recurring']['transaction_amount'] === 240000.0)
                ->andReturn($this->preapproval('pre-y', 'pending'));
        });

        $this->checkout('starter', 'yearly')->assertCreated();
    }

    #[Test]
    public function cambiar_de_plan_cancela_el_debito_anterior_en_mercado_pago(): void
    {
        $this->acc->subscriptions()->create([
            'plan_id' => Plan::where('slug', 'starter')->value('id'), 'status' => Subscription::STATUS_ACTIVE,
            'billing_period' => 'monthly', 'price' => 2500000, 'currency' => 'ARS',
            'payment_method' => Subscription::METHOD_MERCADOPAGO, 'provider_subscription_id' => 'pre-old',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        $this->mock(MercadoPagoSubscriptions::class, function (MockInterface $mp) {
            $mp->shouldReceive('createPending')->once()->andReturn($this->preapproval('pre-new', 'pending'));
            $mp->shouldReceive('find')->with('pre-new')->andReturn($this->preapproval('pre-new', 'authorized'));
            $mp->shouldReceive('find')->with('pre-old')->andReturn($this->preapproval('pre-old', 'authorized'));
            $mp->shouldReceive('transition')->once()->with('pre-old', 'cancel')->andReturn($this->preapproval('pre-old', 'canceled'));
        });

        $this->checkout('pro')->assertCreated();
        $this->postJson('/api/v1/billing/subscriptions/sync', ['accommodation_id' => $this->acc->id])->assertJsonPath('status', 'approved');

        $this->assertSame('pro', $this->acc->fresh()->plan->slug);
        $this->assertSame(1, $this->acc->subscriptions()->live()->count());
    }

    #[Test]
    public function el_debito_cancelado_en_mercado_pago_vuelve_al_free(): void
    {
        $this->acc->update(['plan_id' => Plan::where('slug', 'starter')->value('id')]);
        $this->acc->subscriptions()->create([
            'plan_id' => $this->acc->plan_id, 'status' => Subscription::STATUS_ACTIVE,
            'billing_period' => 'monthly', 'price' => 2500000, 'currency' => 'ARS',
            'payment_method' => Subscription::METHOD_MERCADOPAGO, 'provider_subscription_id' => 'pre-live',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);

        $this->mock(MercadoPagoSubscriptions::class, function (MockInterface $mp) {
            $mp->shouldReceive('find')->with('pre-live')->andReturn($this->preapproval('pre-live', 'paused'), $this->preapproval('pre-live', 'canceled'));
        });
        $billing = app(AccommodationBillingRepository::class);

        $billing->syncPreapproval('pre-live');
        $this->assertSame(Subscription::STATUS_PAST_DUE, $this->acc->subscriptions()->live()->value('status'));

        $billing->syncPreapproval('pre-live');
        $this->assertSame('free', $this->acc->fresh()->plan->slug);
        $this->assertSame(0, $this->acc->subscriptions()->live()->count());
    }

    #[Test]
    public function si_mercado_pago_falla_no_queda_un_pedido_colgado(): void
    {
        $this->mock(MercadoPagoSubscriptions::class, function (MockInterface $mp) {
            $mp->shouldReceive('createPending')->once()->andThrow(new \RuntimeException('Mercado Pago 400: invalid payer'));
        });

        $this->checkout('starter')->assertStatus(502);
        $this->getJson('/api/v1/billing?accommodation_id='.$this->acc->id)->assertJsonPath('pending_request', null);
    }
}
