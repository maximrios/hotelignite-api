<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\ClientPlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Facturación del client (entrega 1): resumen, planes según cómo opera el
 * client, presupuesto, y la edición de precios por el staff.
 */
class ClientBillingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ClientPlanSeeder::class);
    }

    private function actingAsClient(Client $client): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'client '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]));
    }

    private function actingAsPlatform(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'platform '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_PLATFORM,
        ]));
    }

    public function test_un_municipio_entra_con_padron_inicial_y_ve_solo_planes_de_gobierno(): void
    {
        $client = Client::create(['name' => 'Municipio facturación', 'type' => Client::TYPE_GOVERNMENT]);
        $this->actingAsClient($client);

        $response = $this->getJson('/api/client-panel/v1/billing')->assertOk();

        // Todo client entra con el plan free de su tipo.
        $response->assertJsonPath('payment_channel', 'purchase_order')
            ->assertJsonPath('plan.slug', 'client-padron-inicial')
            ->assertJsonPath('usage.api_requests.limit', 1000);

        $slugs = collect($response->json('available_plans'))->pluck('slug');
        $this->assertSame(['client-padron-inicial', 'client-padron', 'client-destino', 'client-provincia'], $slugs->all());
    }

    public function test_una_agencia_entra_con_el_free_y_ve_free_pro_y_venta(): void
    {
        $client = Client::create(['name' => 'Agencia facturación', 'type' => Client::TYPE_AGENCY]);
        $this->actingAsClient($client);

        $response = $this->getJson('/api/client-panel/v1/billing')->assertOk();

        $response->assertJsonPath('payment_channel', 'mercadopago')
            ->assertJsonPath('plan.slug', 'client-agencia-free');
        $this->assertSame(
            ['client-agencia-free', 'client-agencia-pro', 'client-agencia'],
            collect($response->json('available_plans'))->pluck('slug')->all()
        );
    }

    public function test_el_consumo_del_mes_se_mide_contra_el_plan(): void
    {
        $plan = Plan::where('slug', 'client-padron')->firstOrFail();
        $client = Client::create(['name' => 'Municipio con plan', 'type' => Client::TYPE_GOVERNMENT, 'plan_id' => $plan->id]);
        DB::table('client_api_usage')->insert([
            ['client_id' => $client->id, 'date' => now()->startOfMonth()->toDateString(), 'count' => 120],
            ['client_id' => $client->id, 'date' => now()->toDateString(), 'count' => 30],
            // Mes anterior: no cuenta.
            ['client_id' => $client->id, 'date' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'count' => 999],
        ]);
        $client->aiCreditTransactions()->create(['amount' => -40, 'reason' => 'usage', 'period' => now()->format('Y-m')]);
        $this->actingAsClient($client);

        $this->getJson('/api/client-panel/v1/billing')
            ->assertOk()
            ->assertJsonPath('plan.slug', 'client-padron')
            ->assertJsonPath('usage.api_requests.used', 150)
            ->assertJsonPath('usage.api_requests.limit', 10000)
            ->assertJsonPath('usage.ai_credits.used', 40)
            ->assertJsonPath('usage.ai_credits.limit', 500)
            ->assertJsonPath('usage.associates.limit', 50);
    }

    public function test_presupuesto_de_un_plan_ofrecido_y_404_de_uno_ajeno(): void
    {
        $client = Client::create([
            'name' => 'Municipio presupuesto',
            'type' => Client::TYPE_GOVERNMENT,
            'legal_name' => 'Municipalidad de Prueba',
            'tax_id' => '30-12345678-9',
        ]);
        $this->actingAsClient($client);

        $this->getJson('/api/client-panel/v1/billing/quote?plan=client-destino')
            ->assertOk()
            ->assertJsonPath('buyer.tax_id', '30-12345678-9')
            ->assertJsonPath('total', Plan::where('slug', 'client-destino')->value('price'))
            ->assertJsonPath('price_provisional', true);

        // El plan de agencia no se le ofrece a un municipio, ni uno de hoteleros.
        $this->getJson('/api/client-panel/v1/billing/quote?plan=client-agencia')->assertNotFound();
        $this->getJson('/api/client-panel/v1/billing/quote?plan=starter')->assertNotFound();
    }

    public function test_los_selectores_del_crm_no_mezclan_planes_de_clients(): void
    {
        $this->actingAsPlatform();

        $hoteleros = collect($this->getJson('/api/admin/v1/plans')->assertOk()->json('data'))->pluck('audience')->unique();
        $clients = collect($this->getJson('/api/admin/v1/plans?audience=client')->assertOk()->json('data'))->pluck('audience')->unique();

        $this->assertNotContains('client', $hoteleros->all());
        $this->assertSame(['client'], $clients->values()->all());
    }

    public function test_el_staff_edita_el_precio_y_un_client_no(): void
    {
        $plan = Plan::where('slug', 'client-padron')->firstOrFail();

        $this->actingAsPlatform();
        $this->patchJson("/api/admin/v1/plans/{$plan->id}", ['price' => 99_900_000, 'price_provisional' => false])
            ->assertOk()
            ->assertJsonPath('data.price', 99_900_000)
            ->assertJsonPath('data.price_provisional', false);

        // Re-sembrar no pisa un precio ya editado.
        $this->seed(ClientPlanSeeder::class);
        $this->assertSame(99_900_000, $plan->fresh()->price);

        $this->actingAsClient(Client::create(['name' => 'Municipio sin permiso', 'type' => Client::TYPE_GOVERNMENT]));
        $this->patchJson("/api/admin/v1/plans/{$plan->id}", ['price' => 1])->assertForbidden();
    }
}
