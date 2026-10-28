<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\MercadoPagoNotification;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\MercadoPagoSubscriptions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use MercadoPago\Resources\Invoice;
use MercadoPago\Resources\PreApproval;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Webhook de Mercado Pago: firma HMAC, registro del aviso y procesamiento con la API simulada. */
class MercadoPagoWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'secreto-de-prueba';

    private Accommodation $acc;

    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mercadopago.webhook_secret' => self::SECRET]);

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);
        $account = Account::create(['name' => 'Cuenta webhook test']);
        $this->acc = Accommodation::create([
            'account_id' => $account->id, 'city_id' => $cityId,
            'name' => 'Hotel webhook test', 'slug' => 'hotel-webhook-test-'.uniqid(),
            'plan_id' => Plan::where('slug', 'starter')->value('id'),
        ]);
        $this->sub = $this->acc->subscriptions()->create([
            'plan_id' => $this->acc->plan_id, 'status' => Subscription::STATUS_ACTIVE,
            'billing_period' => 'monthly', 'price' => 2500000, 'currency' => 'ARS',
            'payment_method' => Subscription::METHOD_MERCADOPAGO, 'provider_subscription_id' => 'pre-live',
            'current_period_start' => '2026-10-01', 'current_period_end' => '2026-11-01',
        ]);
    }

    /** Aviso firmado como lo firma Mercado Pago (manifest de la documentación oficial). */
    private function notify(string $type, string $dataId, ?string $secret = null)
    {
        $ts = (string) (time() * 1000);
        $requestId = 'req-'.uniqid();
        $manifest = 'id:'.strtolower($dataId).";request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, $secret ?? self::SECRET);

        return $this->postJson("/api/v1/mercadopago/webhook?data.id={$dataId}&type={$type}", [
            'type' => $type, 'action' => 'updated', 'data' => ['id' => $dataId],
        ], ['x-signature' => "ts={$ts},v1={$v1}", 'x-request-id' => $requestId]);
    }

    private function charge(int $paymentId, string $status, string $debitDate): Invoice
    {
        $i = new Invoice;
        $i->id = 7001;
        $i->preapproval_id = 'pre-live';
        $i->status = 'processed';
        $i->transaction_amount = 25000.0;
        $i->currency_id = 'ARS';
        $i->reason = 'HotelIgnite Básico (mensual)';
        $i->debit_date = $debitDate;
        $i->payment = ['id' => $paymentId, 'status' => $status];

        return $i;
    }

    #[Test]
    public function rechaza_una_firma_invalida_sin_guardar_nada(): void
    {
        $this->notify('subscription_preapproval', 'pre-live', 'otro-secreto')->assertUnauthorized();
        $this->postJson('/api/v1/mercadopago/webhook?data.id=pre-live&type=subscription_preapproval', ['type' => 'x'])->assertUnauthorized();

        $this->assertSame(0, MercadoPagoNotification::where('resource_id', 'pre-live')->count());
    }

    #[Test]
    public function el_debito_pausado_marca_la_suscripcion_vencida(): void
    {
        $this->mock(MercadoPagoSubscriptions::class, function (MockInterface $mp) {
            $p = new PreApproval;
            $p->id = 'pre-live';
            $p->status = 'paused';
            $mp->shouldReceive('find')->with('pre-live')->andReturn($p);
        });

        $this->notify('subscription_preapproval', 'pre-live')->assertOk();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $this->sub->fresh()->status);
        $this->assertNotNull(MercadoPagoNotification::where('resource_id', 'pre-live')->value('processed_at'));
    }

    #[Test]
    public function el_cobro_aprobado_registra_el_pago_y_renueva_el_periodo_una_sola_vez(): void
    {
        $this->mock(MercadoPagoSubscriptions::class, function (MockInterface $mp) {
            $mp->shouldReceive('findAuthorizedPayment')->with(7001)->andReturn($this->charge(9001, 'approved', '2026-11-01T10:00:00.000-03:00'));
        });

        $this->notify('subscription_authorized_payment', '7001')->assertOk();
        $this->notify('subscription_authorized_payment', '7001')->assertOk(); // reintento de Mercado Pago

        $sub = $this->sub->fresh();
        $this->assertSame('2026-11-01', $sub->current_period_start->toDateString());
        $this->assertSame('2026-12-01', $sub->current_period_end->toDateString());

        $payments = Payment::where('provider_payment_id', '9001')->get();
        $this->assertCount(1, $payments);
        $this->assertSame('approved', $payments[0]->status);
        $this->assertSame(2500000, $payments[0]->amount);
        $this->assertSame(2, MercadoPagoNotification::where('resource_id', '7001')->value('received_count'));
    }

    #[Test]
    public function el_cobro_rechazado_deja_la_suscripcion_vencida(): void
    {
        $this->mock(MercadoPagoSubscriptions::class, function (MockInterface $mp) {
            $mp->shouldReceive('findAuthorizedPayment')->andReturn($this->charge(9002, 'rejected', '2026-11-01T10:00:00.000-03:00'));
        });

        $this->notify('subscription_authorized_payment', '7001')->assertOk();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $this->sub->fresh()->status);
        $this->assertSame('2026-11-01', $this->sub->fresh()->current_period_end->toDateString());
    }

    #[Test]
    public function si_mercado_pago_falla_responde_200_y_queda_para_reintentar(): void
    {
        $this->mock(MercadoPagoSubscriptions::class, function (MockInterface $mp) {
            $mp->shouldReceive('find')->once()->andThrow(new \RuntimeException('Mercado Pago 500: boom'));
            $p = new PreApproval;
            $p->id = 'pre-live';
            $p->status = 'paused';
            $mp->shouldReceive('find')->andReturn($p);
        });

        $this->notify('subscription_preapproval', 'pre-live')->assertOk();
        $row = MercadoPagoNotification::where('resource_id', 'pre-live')->first();
        $this->assertNull($row->processed_at);
        $this->assertStringContainsString('boom', $row->last_error);

        $row->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
        $this->artisan('mercadopago:retry-notifications')->assertSuccessful();

        $this->assertNotNull($row->fresh()->processed_at);
        $this->assertSame(Subscription::STATUS_PAST_DUE, $this->sub->fresh()->status);
    }
}
