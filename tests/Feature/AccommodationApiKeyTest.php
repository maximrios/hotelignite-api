<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationApiKey;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientApiKey;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API keys del alojamiento (docs/accommodation-mcp-plan.md, fase 1): emisión
 * desde el panel del dueño y el middleware `auth.accommodation`.
 *
 * El middleware todavía no tiene ruta propia (el MCP es la fase 2): se prueba
 * contra una ruta registrada en el test con la misma pila de middlewares.
 *
 * Usa DatabaseTransactions por el mismo motivo que AccommodationTenancyTest:
 * el schema real viene de un dump.
 */
class AccommodationApiKeyTest extends TestCase
{
    use DatabaseTransactions;

    private const PROBE = '/_test/accommodation-key';

    private Plan $withAssistant;

    private Plan $withoutAssistant;

    private Account $account;

    private Accommodation $acc;

    protected function setUp(): void
    {
        parent::setUp();

        $feature = Feature::firstOrCreate(
            ['slug' => AccommodationApiKey::FEATURE],
            ['name' => 'Asistente con IA para huéspedes', 'module' => 'platform', 'type' => 'boolean'],
        );

        $this->withAssistant = Plan::create(['name' => 'Con asistente', 'slug' => 'test-ai-'.uniqid()]);
        $this->withAssistant->features()->attach($feature->id);
        $this->withoutAssistant = Plan::create(['name' => 'Sin asistente', 'slug' => 'test-free-'.uniqid()]);

        $this->account = Account::create(['name' => 'Cuenta test']);
        $this->acc = $this->accommodation($this->account, $this->withAssistant);

        Route::middleware(['auth.accommodation', 'throttle:accommodation', 'accommodation.ability:mcp:read'])
            ->get(self::PROBE, fn (Request $request) => response()->json([
                'accommodation_id' => $request->attributes->get('api_accommodation')?->id,
                'user_type' => $request->user()?->user_type,
                'account_id' => $request->user()?->account_id,
            ]));
    }

    // --- Emisión desde el panel -------------------------------------------------

    #[Test]
    public function el_dueno_genera_una_key_y_el_secreto_se_ve_una_sola_vez(): void
    {
        Sanctum::actingAs($this->accountUser($this->account));

        $response = $this->postJson($this->keysUrl($this->acc), ['name' => 'Colmexa'])->assertStatus(201);

        $secret = (string) $response->json('secret');
        $this->assertStringStartsWith('ak_live_', $secret);
        $this->assertSame(AccommodationApiKey::ABILITIES, $response->json('key.abilities'));

        $key = AccommodationApiKey::findOrFail($response->json('key.id'));
        $this->assertSame((int) $this->account->id, (int) $key->account_id);
        $this->assertSame(hash('sha256', $secret), $key->key_hash);
        $this->assertSame($key->prefix, $response->json('client_id'));
        $this->assertSame($secret, $response->json('client_id').'_'.$response->json('client_secret'));

        $listed = $this->getJson($this->keysUrl($this->acc))->assertOk();
        $this->assertTrue($listed->json('meta.ai_assistant'));
        $this->assertStringNotContainsString($secret, $listed->getContent());
        $this->assertStringNotContainsString($key->key_hash, $listed->getContent());
    }

    #[Test]
    public function sin_la_feature_en_el_plan_no_se_puede_generar(): void
    {
        $acc = $this->accommodation($this->account, $this->withoutAssistant);
        Sanctum::actingAs($this->accountUser($this->account));

        $this->postJson($this->keysUrl($acc))->assertStatus(403);
        $this->assertFalse($this->getJson($this->keysUrl($acc))->json('meta.ai_assistant'));
    }

    #[Test]
    public function otra_cuenta_no_lista_genera_ni_revoca(): void
    {
        $key = AccommodationApiKey::generateFor($this->acc)['model'];
        Sanctum::actingAs($this->accountUser(Account::create(['name' => 'Otra cuenta'])));

        $this->getJson($this->keysUrl($this->acc))->assertStatus(403);
        $this->postJson($this->keysUrl($this->acc))->assertStatus(403);
        $this->deleteJson($this->keysUrl($this->acc)."/{$key->id}")->assertStatus(403);
    }

    #[Test]
    public function un_client_no_llega_a_las_keys(): void
    {
        $client = Client::create(['name' => 'Client test', 'type' => 'agency', 'active' => true]);
        $this->acc->clients()->attach($client->id, ['status' => 'active']);

        Sanctum::actingAs(User::create([
            'name' => 'client '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]));

        $this->getJson($this->keysUrl($this->acc))->assertStatus(403);
        $this->postJson($this->keysUrl($this->acc))->assertStatus(403);
    }

    #[Test]
    public function rechaza_abilities_que_no_existen(): void
    {
        Sanctum::actingAs($this->accountUser($this->account));

        $this->postJson($this->keysUrl($this->acc), ['abilities' => ['booking:create']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abilities.0');
    }

    #[Test]
    public function revocar_una_key_de_otro_alojamiento_es_404(): void
    {
        $other = $this->accommodation($this->account, $this->withAssistant);
        $key = AccommodationApiKey::generateFor($other)['model'];
        Sanctum::actingAs($this->accountUser($this->account));

        $this->deleteJson($this->keysUrl($this->acc)."/{$key->id}")->assertStatus(404);
        $this->assertNull($key->fresh()->revoked_at);
    }

    #[Test]
    public function revocar_corta_el_acceso(): void
    {
        ['model' => $key, 'plain' => $plain] = AccommodationApiKey::generateFor($this->acc);
        Sanctum::actingAs($this->accountUser($this->account));

        $this->deleteJson($this->keysUrl($this->acc)."/{$key->id}")
            ->assertOk()
            ->assertJsonPath('key.active', false);

        $this->probe($plain)->assertStatus(401);
    }

    // --- Middleware auth.accommodation -------------------------------------------

    #[Test]
    public function una_key_valida_entra_como_la_cuenta_y_deja_el_alojamiento(): void
    {
        $plain = AccommodationApiKey::generateFor($this->acc)['plain'];

        $this->probe($plain)
            ->assertOk()
            ->assertJson([
                'accommodation_id' => $this->acc->id,
                'user_type' => User::TYPE_ACCOUNT,
                'account_id' => $this->account->id,
            ]);

        $this->assertNotNull(AccommodationApiKey::where('accommodation_id', $this->acc->id)->value('last_used_at'));
    }

    #[Test]
    public function la_key_partida_como_client_id_punto_client_secret_tambien_entra(): void
    {
        $result = AccommodationApiKey::generateFor($this->acc);
        $prefix = $result['model']->prefix;
        $secret = substr($result['plain'], strlen($prefix) + 1);

        // Es como manda el Bearer Colmexa: client_id.client_secret.
        $this->probe("{$prefix}.{$secret}")
            ->assertOk()
            ->assertJson(['accommodation_id' => $this->acc->id]);

        $this->probe("{$prefix}.".substr($secret, 0, -1).'X')->assertStatus(401);
    }

    #[Test]
    public function sin_credencial_o_con_formato_ajeno_responde_401(): void
    {
        $this->getJson(self::PROBE)->assertStatus(401);
        $this->probe('cualquier-cosa')->assertStatus(401);

        // Una key de client no sirve acá, aunque sea válida en client/v1.
        $client = Client::create(['name' => 'Client test', 'type' => 'agency', 'active' => true]);
        $this->probe(ClientApiKey::generateFor($client, null, ['mcp:read'])['plain'])->assertStatus(401);
    }

    #[Test]
    public function un_secreto_alterado_responde_401(): void
    {
        $plain = AccommodationApiKey::generateFor($this->acc)['plain'];

        $this->probe(substr($plain, 0, -1).'X')->assertStatus(401);
    }

    #[Test]
    public function una_key_de_alojamiento_no_entra_a_client_v1(): void
    {
        $plain = AccommodationApiKey::generateFor($this->acc)['plain'];

        $this->getJson('/api/client/v1/accommodations', ['Authorization' => "Bearer {$plain}"])->assertStatus(401);
    }

    #[Test]
    public function una_key_expirada_responde_401(): void
    {
        $plain = AccommodationApiKey::generateFor($this->acc, null, AccommodationApiKey::ABILITIES, now()->addMinute())['plain'];
        $this->travel(2)->minutes();

        $this->probe($plain)->assertStatus(401);
    }

    #[Test]
    public function si_el_alojamiento_cambia_de_cuenta_la_key_deja_de_valer(): void
    {
        $plain = AccommodationApiKey::generateFor($this->acc)['plain'];
        $this->acc->update(['account_id' => Account::create(['name' => 'Comprador'])->id]);

        $this->probe($plain)->assertStatus(401);
    }

    #[Test]
    public function un_alojamiento_deshabilitado_responde_401(): void
    {
        $plain = AccommodationApiKey::generateFor($this->acc)['plain'];
        $this->acc->update(['enabled' => false]);

        $this->probe($plain)->assertStatus(401);
    }

    #[Test]
    public function bajar_de_plan_apaga_la_key_con_403(): void
    {
        $plain = AccommodationApiKey::generateFor($this->acc)['plain'];
        $this->acc->update(['plan_id' => $this->withoutAssistant->id]);

        $this->probe($plain)->assertStatus(403);
    }

    #[Test]
    public function una_key_sin_la_ability_responde_403(): void
    {
        $plain = AccommodationApiKey::generateFor($this->acc, null, ['inquiry:create'])['plain'];

        $this->probe($plain)->assertStatus(403);
    }

    // --- Helpers -------------------------------------------------------------------

    private function probe(string $credential): TestResponse
    {
        return $this->getJson(self::PROBE, ['Authorization' => "Bearer {$credential}"]);
    }

    private function keysUrl(Accommodation $accommodation): string
    {
        return "/api/admin/v1/accommodations/{$accommodation->id}/api-keys";
    }

    private function accommodation(Account $account, Plan $plan): Accommodation
    {
        return Accommodation::create([
            'account_id' => $account->id,
            'plan_id' => $plan->id,
            'name' => 'Hotel test',
            'slug' => 'hotel-test-'.uniqid(),
            'enabled' => 1,
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
}
