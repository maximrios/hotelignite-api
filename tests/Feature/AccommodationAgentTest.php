<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationAgent;
use App\Models\AccommodationApiKey;
use App\Models\Account;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Página "Agentes IA" del PMS (docs/ai-agents-plan.md): catálogo, activación en
 * Colmexa con key rotada, créditos del mes y apagado. Colmexa se simula con
 * Http::fake; su lado está probado en colmexa/backend (PartnerApiTest).
 *
 * DatabaseTransactions por el mismo motivo que AccommodationTenancyTest: el
 * schema real viene de un dump.
 */
class AccommodationAgentTest extends TestCase
{
    use DatabaseTransactions;

    private const COLMEXA = 'http://colmexa.test';

    private Plan $plan;

    private Account $account;

    private Accommodation $acc;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.colmexa.url' => self::COLMEXA,
            'services.colmexa.partner_token' => 'partner-token',
            'services.colmexa.mcp_base_url' => 'http://nginx',
        ]);

        $feature = Feature::firstOrCreate(
            ['slug' => AccommodationApiKey::FEATURE],
            ['name' => 'Asistente con IA para huéspedes', 'module' => 'platform', 'type' => 'boolean'],
        );
        $this->plan = Plan::create(['name' => 'Pro', 'slug' => 'test-ai-'.uniqid()]);
        $this->plan->features()->attach($feature->id, ['limit' => 500]);

        $this->account = Account::create(['name' => 'Cuenta test']);
        $this->acc = Accommodation::create([
            'account_id' => $this->account->id,
            'plan_id' => $this->plan->id,
            'name' => 'Hotel Bonarda',
            'slug' => 'hotel-bonarda-'.uniqid(),
            'timezone' => 'America/Argentina/Salta',
            'enabled' => 1,
        ]);

        Http::fake([
            self::COLMEXA.'/api/partner/v1/tenants/*/agents/*' => fn (Request $request) => $request->method() === 'DELETE'
                ? Http::response(null, 204)
                : Http::response(['data' => [
                    'slug' => 'recepcionista',
                    'status' => 'published',
                    'connector' => ['tools_count' => 3, 'has_instructions' => true, 'last_error' => null],
                    'web' => [
                        'public_key' => 'pk_web_123',
                        'script_url' => self::COLMEXA.'/widget.js',
                        'snippet' => '<script src="'.self::COLMEXA.'/widget.js" data-key="pk_web_123" defer></script>',
                    ],
                ]]),
            self::COLMEXA.'/api/partner/v1/tenants/*/credits' => Http::response(['data' => ['balance' => 300]], 201),
            self::COLMEXA.'/api/partner/v1/tenants/*' => Http::response(['data' => ['ref' => (string) $this->acc->id]], 201),
        ]);
    }

    #[Test]
    public function lista_el_catalogo_con_lo_que_falta_en_la_ficha(): void
    {
        Sanctum::actingAs($this->owner());

        $response = $this->getJson($this->url())->assertOk();

        $agents = collect($response->json('data'))->keyBy('slug');
        $this->assertTrue($agents['recepcionista']['available']);
        $this->assertTrue($agents['recepcionista']['included_in_plan']);
        $this->assertSame('inactive', $agents['recepcionista']['status']);
        $this->assertFalse($agents['revenue']['available']);

        $readiness = collect($response->json('meta.readiness'))->keyBy('key');
        $this->assertFalse($readiness['rooms']['ok']);
        $this->assertSame('required', $readiness['rooms']['level']);
        $this->assertTrue($response->json('meta.service_available'));
    }

    #[Test]
    public function quien_activa_queda_como_owner_de_la_bandeja_una_sola_vez(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $this->putJson($this->url('recepcionista'))
            ->assertOk()
            ->assertJsonPath('data.inbox_owner', $owner->email);

        // Cambiar el saludo no vuelve a invitar: el equipo ya lo maneja el hotel.
        $this->putJson($this->url('recepcionista'), ['greeting' => 'Hola'])
            ->assertOk()
            ->assertJsonPath('data.inbox_owner', $owner->email);

        $owners = Http::recorded(fn (Request $request) => str_ends_with($request->url(), "/tenants/{$this->acc->id}/owner"));
        $this->assertCount(1, $owners);
        $this->assertSame($owner->email, $owners->first()[0]['email']);
    }

    #[Test]
    public function si_activa_plataforma_el_owner_es_el_usuario_mas_antiguo_de_la_cuenta(): void
    {
        $first = $this->owner();
        $this->owner();
        Sanctum::actingAs(User::create([
            'name' => 'staff',
            'email' => uniqid().'@hotelignite.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_PLATFORM,
        ]));

        $this->putJson($this->url('recepcionista'))
            ->assertOk()
            ->assertJsonPath('data.inbox_owner', $first->email);
    }

    #[Test]
    public function activar_crea_el_tenant_el_agente_y_carga_el_mes(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson($this->url('recepcionista'), ['allowed_origins' => ['https://hotelbonarda.com']])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.widget.public_key', 'pk_web_123')
            ->assertJsonPath('data.settings.allowed_origins', ['https://hotelbonarda.com']);

        $record = AccommodationAgent::query()->where('accommodation_id', $this->acc->id)->firstOrFail();
        $key = AccommodationApiKey::query()->findOrFail($record->api_key_id);
        $this->assertTrue($key->isActive());

        Http::assertSent(function (Request $request) use ($key) {
            return $request->method() === 'PUT'
                && str_ends_with($request->url(), "/tenants/{$this->acc->id}/agents/recepcionista")
                && $request->hasHeader('Authorization', 'Bearer partner-token')
                && $request['connector']['mcp_url'] === 'http://nginx/mcp/accommodation'
                && $request['connector']['client_id'] === $key->prefix
                && $request['web']['allowed_origins'] === ['https://hotelbonarda.com']
                && $request['settings']['business_name'] === 'Hotel Bonarda';
        });

        $month = now('America/Argentina/Salta')->format('Y-m');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/credits')
            && $request['amount'] === 500
            && $request['reference'] === "plan-{$month}");
    }

    #[Test]
    public function la_key_que_recibe_colmexa_entra_al_mcp(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson($this->url('recepcionista'))->assertOk();

        $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/agents/recepcionista'))->first()[0];

        $this->app['auth']->forgetGuards();
        $this->withToken($sent['connector']['client_id'].'.'.$sent['connector']['client_secret'])
            ->postJson('/mcp/accommodation', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', fn (string $name) => $name !== '');
    }

    #[Test]
    public function volver_a_sincronizar_rota_la_key_y_revoca_la_anterior(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson($this->url('recepcionista'))->assertOk();
        $first = AccommodationAgent::query()->where('accommodation_id', $this->acc->id)->firstOrFail()->api_key_id;

        $this->putJson($this->url('recepcionista'), ['greeting' => '¡Hola! Soy el asistente del Bonarda.'])->assertOk();
        $record = AccommodationAgent::query()->where('accommodation_id', $this->acc->id)->firstOrFail();

        $this->assertNotSame($first, $record->api_key_id);
        $this->assertTrue(AccommodationApiKey::query()->findOrFail($first)->isRevoked());
        $this->assertTrue(AccommodationApiKey::query()->findOrFail($record->api_key_id)->isActive());
    }

    #[Test]
    public function si_colmexa_falla_no_queda_una_key_viva_de_mas(): void
    {
        // Factory nueva: los stubs del setUp ganarían (el primero que coincide).
        Http::swap(new Factory);
        Http::fake([self::COLMEXA.'/*' => Http::response(['message' => 'caído'], 500)]);
        Sanctum::actingAs($this->owner());

        $this->putJson($this->url('recepcionista'))->assertStatus(503);

        $this->assertSame(0, AccommodationApiKey::query()
            ->where('accommodation_id', $this->acc->id)
            ->whereNull('revoked_at')
            ->count());
        $this->assertSame('error', AccommodationAgent::query()->where('accommodation_id', $this->acc->id)->value('status'));
    }

    #[Test]
    public function sin_la_feature_del_plan_no_se_activa(): void
    {
        $this->acc->update(['plan_id' => Plan::create(['name' => 'Free', 'slug' => 'test-free-'.uniqid()])->id]);
        Sanctum::actingAs($this->owner());

        $this->putJson($this->url('recepcionista'))->assertForbidden();
        Http::assertNothingSent();
    }

    #[Test]
    public function un_agente_proximamente_no_se_activa(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson($this->url('revenue'))->assertStatus(422);
        $this->putJson($this->url('no-existe'))->assertNotFound();
    }

    #[Test]
    public function otra_cuenta_no_ve_ni_activa_los_agentes(): void
    {
        Sanctum::actingAs($this->owner(Account::create(['name' => 'Otra cuenta'])));

        $this->getJson($this->url())->assertForbidden();
        $this->putJson($this->url('recepcionista'))->assertForbidden();
    }

    #[Test]
    public function apagar_desactiva_en_colmexa_y_revoca_la_key(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson($this->url('recepcionista'))->assertOk();
        $keyId = AccommodationAgent::query()->where('accommodation_id', $this->acc->id)->value('api_key_id');

        $this->deleteJson($this->url('recepcionista'))
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.widget', null);

        $this->assertTrue(AccommodationApiKey::query()->findOrFail($keyId)->isRevoked());
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    #[Test]
    public function un_sitio_con_ruta_se_rechaza(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson($this->url('recepcionista'), ['allowed_origins' => ['https://hotelbonarda.com/reservas']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('allowed_origins.0');
    }

    #[Test]
    public function abrir_bandeja_pide_el_enlace_con_el_email_del_usuario(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $this->putJson($this->url('recepcionista'))->assertOk();

        $this->fakeLoginLink(Http::response(['data' => ['url' => 'https://inbox.test/entrar?token=abc', 'expires_at' => now()->toIso8601String()]], 201));

        $this->postJson($this->inboxUrl())
            ->assertOk()
            ->assertJsonPath('data.url', 'https://inbox.test/entrar?token=abc');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/tenants/{$this->acc->id}/login-links")
            && $request['email'] === $owner->email
            && $request['redirect_to'] === '/conversaciones');
    }

    #[Test]
    public function quien_no_es_del_equipo_de_la_bandeja_sabe_a_quien_pedirselo(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $this->putJson($this->url('recepcionista'))->assertOk();

        $colleague = $this->owner();
        Sanctum::actingAs($colleague);
        $this->fakeLoginLink(Http::response(['message' => 'Esa persona no tiene acceso.'], 404));

        $this->postJson($this->inboxUrl())
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, $owner->email));
    }

    #[Test]
    public function sin_agente_activo_no_hay_bandeja(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson($this->inboxUrl())->assertStatus(422);
        Http::assertNothingSent();
    }

    private function inboxUrl(): string
    {
        return "/api/admin/v1/accommodations/{$this->acc->id}/inbox-link";
    }

    /** Factory nueva: los stubs del setUp ganarían (el primero que coincide). */
    private function fakeLoginLink(PromiseInterface $response): void
    {
        Http::swap(new Factory);
        Http::fake([self::COLMEXA.'/api/partner/v1/tenants/*/login-links' => $response]);
    }

    private function url(?string $agent = null): string
    {
        return "/api/admin/v1/accommodations/{$this->acc->id}/agents".($agent !== null ? "/{$agent}" : '');
    }

    private function owner(?Account $account = null): User
    {
        return User::create([
            'name' => 'user '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => ($account ?? $this->account)->id,
        ]);
    }
}
