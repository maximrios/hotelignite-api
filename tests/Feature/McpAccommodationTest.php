<?php

namespace Tests\Feature;

use App\Mail\InquiryReceivedMail;
use App\Models\Accommodation;
use App\Models\AccommodationApiKey;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientApiKey;
use App\Models\Feature;
use App\Models\Inquiry;
use App\Models\Plan;
use App\Models\RoomAvailability;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MCP del alojamiento (/mcp/accommodation, fase 2 de
 * docs/accommodation-mcp-plan.md): autenticación por key de alojamiento y, sobre
 * todo, que las herramientas no salgan del alojamiento de la key aunque la cuenta
 * tenga otros o el modelo mande ids.
 *
 * Usa DatabaseTransactions por el mismo motivo que AccommodationTenancyTest:
 * el schema real viene de un dump.
 */
class McpAccommodationTest extends TestCase
{
    use DatabaseTransactions;

    private Plan $plan;

    private Account $account;

    private Accommodation $acc;

    /** Otro alojamiento de la MISMA cuenta: el actor lo ve, las herramientas no. */
    private Accommodation $sibling;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.mock_missing_data' => false]);

        $feature = Feature::firstOrCreate(
            ['slug' => AccommodationApiKey::FEATURE],
            ['name' => 'Asistente con IA para huéspedes', 'module' => 'platform', 'type' => 'boolean'],
        );
        $this->plan = Plan::create(['name' => 'Con asistente', 'slug' => 'test-ai-'.uniqid()]);
        $this->plan->features()->attach($feature->id);

        $this->account = Account::create(['name' => 'Cuenta test']);
        $this->acc = $this->accommodation('Hostería del Cerro');
        $this->sibling = $this->accommodation('Cabañas del Río');

        $this->key = AccommodationApiKey::generateFor($this->acc)['plain'];
    }

    #[Test]
    public function lista_solo_las_herramientas_del_alojamiento(): void
    {
        $names = collect($this->mcp('tools/list')->assertOk()->json('result.tools'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['check_availability', 'create_inquiry', 'get_accommodation_info'], $names);
    }

    #[Test]
    public function una_herramienta_sin_argumentos_publica_properties_como_objeto(): void
    {
        $content = $this->mcp('tools/list')->assertOk()->getContent();

        $this->assertStringNotContainsString('"properties":[]', $content);
        $this->assertStringContainsString('"properties":{}', $content);
    }

    #[Test]
    public function la_ficha_es_la_del_alojamiento_de_la_key(): void
    {
        $info = $this->tool('get_accommodation_info');

        $this->assertSame($this->acc->id, $info['id']);
        $this->assertSame('Hostería del Cerro', $info['name']);
    }

    #[Test]
    public function un_slug_mandado_por_el_modelo_no_cambia_de_alojamiento(): void
    {
        $info = $this->tool('get_accommodation_info', ['slug' => $this->sibling->slug, 'accommodation_id' => $this->sibling->id]);

        $this->assertSame($this->acc->id, $info['id']);
    }

    #[Test]
    public function la_disponibilidad_es_la_del_alojamiento_de_la_key(): void
    {
        $result = $this->availability(['accommodation_id' => $this->sibling->id]);

        $this->assertSame($this->acc->id, $result['accommodation']['id']);
    }

    #[Test]
    public function sin_inventario_la_disponibilidad_es_unknown(): void
    {
        $result = $this->availability();

        $this->assertSame('unknown', $result['status']);
        $this->assertNull($result['price_from']);
    }

    #[Test]
    public function con_inventario_real_abierto_esta_disponible(): void
    {
        $roomType = RoomType::create([
            'accommodation_id' => $this->acc->id,
            'slug' => 'doble-'.uniqid(),
            'max_occupancy' => 2,
            'quantity' => 1,
        ]);

        for ($i = 0; $i < 2; $i++) {
            RoomAvailability::create([
                'room_type_id' => $roomType->id,
                'date' => $this->checkin()->addDays($i)->toDateString(),
                'available' => 1,
                'total' => 1,
                'closed' => false,
            ]);
        }

        $this->assertSame('available', $this->availability()['status']);
    }

    #[Test]
    public function fechas_invalidas_vuelven_como_error_de_la_herramienta(): void
    {
        $result = $this->callTool('check_availability', [
            'checkin' => CarbonImmutable::yesterday()->toDateString(),
            'checkout' => CarbonImmutable::today()->addDay()->toDateString(),
            'adults' => 2,
        ]);

        $this->assertTrue($result['isError']);
    }

    #[Test]
    public function el_agente_deja_una_consulta_sin_email_y_el_hotelero_la_ve_y_recibe_el_mail(): void
    {
        Mail::fake();
        $owner = $this->accountUser();

        $result = $this->tool('create_inquiry', $this->inquiryArgs());
        $this->assertSame('received', $result['status']);

        $inquiry = Inquiry::findOrFail($result['inquiry_id']);
        $this->assertSame(Inquiry::SOURCE_AGENT, $inquiry->source);
        $this->assertSame($this->acc->id, (int) $inquiry->inquirable_id);
        $this->assertNull($inquiry->email);
        $this->assertNull($inquiry->client_id);

        Mail::assertQueued(InquiryReceivedMail::class, fn (InquiryReceivedMail $mail) => $mail->hasTo($owner->email));

        $html = (new InquiryReceivedMail($inquiry))->render();
        $this->assertStringContainsString('Hostería del Cerro', $html);
        $this->assertStringContainsString('a través del asistente', $html);
        $this->assertStringContainsString('+5493875550000', $html);
        $this->assertStringContainsString('/consultas', $html);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/inquiries?accommodation_id={$this->acc->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $inquiry->id)
            ->assertJsonPath('data.0.source', 'agent')
            ->assertJsonPath('data.0.email', null);
    }

    #[Test]
    public function la_consulta_es_del_alojamiento_de_la_key_aunque_el_modelo_mande_otro(): void
    {
        $result = $this->tool('create_inquiry', $this->inquiryArgs(['accommodation_id' => $this->sibling->id]));

        $this->assertSame($this->acc->id, (int) Inquiry::findOrFail($result['inquiry_id'])->inquirable_id);
    }

    #[Test]
    public function un_reintento_del_agente_no_duplica_la_consulta(): void
    {
        $first = $this->tool('create_inquiry', $this->inquiryArgs());
        $second = $this->tool('create_inquiry', $this->inquiryArgs(['message' => 'Repite la pregunta']));

        $this->assertSame($first['inquiry_id'], $second['inquiry_id']);
        $this->assertSame(1, Inquiry::query()->forAccommodation($this->acc->id)->count());

        // Pasada la ventana, es otra consulta.
        $this->travel(31)->minutes();
        $third = $this->tool('create_inquiry', $this->inquiryArgs());
        $this->assertNotSame($first['inquiry_id'], $third['inquiry_id']);
    }

    #[Test]
    public function una_key_sin_inquiry_create_no_deja_consultas(): void
    {
        $this->key = AccommodationApiKey::generateFor($this->acc, null, ['mcp:read'])['plain'];

        $result = $this->callTool('create_inquiry', $this->inquiryArgs());

        $this->assertTrue($result['isError']);
        $this->assertSame(0, Inquiry::query()->forAccommodation($this->acc->id)->count());
    }

    #[Test]
    public function una_consulta_sin_telefono_es_un_error_de_la_herramienta(): void
    {
        $args = $this->inquiryArgs();
        unset($args['phone']);

        $this->assertTrue($this->callTool('create_inquiry', $args)['isError']);
    }

    #[Test]
    public function el_client_que_tiene_al_alojamiento_en_el_padron_no_ve_las_del_asistente(): void
    {
        $client = Client::create(['name' => 'Municipio test', 'type' => 'government', 'active' => true]);
        $this->acc->clients()->attach($client->id, ['status' => 'active']);
        $id = $this->tool('create_inquiry', $this->inquiryArgs())['inquiry_id'];

        $clientUser = User::create([
            'name' => 'client '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]);

        $this->assertFalse(Inquiry::query()->visibleTo($clientUser)->whereKey($id)->exists());
        $this->assertTrue(Inquiry::query()->visibleTo($this->accountUser())->whereKey($id)->exists());
    }

    #[Test]
    public function si_el_alojamiento_se_deshabilita_la_key_deja_de_entrar(): void
    {
        $this->acc->update(['enabled' => false]);

        $this->mcp('tools/list')->assertStatus(401);
    }

    #[Test]
    public function una_key_sin_mcp_read_responde_403(): void
    {
        $this->key = AccommodationApiKey::generateFor($this->acc, null, ['inquiry:create'])['plain'];

        $this->mcp('tools/list')->assertStatus(403);
    }

    #[Test]
    public function una_key_de_client_no_entra(): void
    {
        $client = Client::create(['name' => 'Client test', 'type' => 'agency', 'active' => true]);
        $this->key = ClientApiKey::generateFor($client, null, ['mcp:read'])['plain'];

        $this->mcp('tools/list')->assertStatus(401);
    }

    #[Test]
    public function una_key_de_alojamiento_no_entra_al_mcp_del_viajero(): void
    {
        $this->postJson('/mcp/traveler', $this->rpc('tools/list'), $this->headers())->assertStatus(401);
    }

    // --- helpers ---

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function inquiryArgs(array $overrides = []): array
    {
        return [
            'name' => 'Lucía',
            'phone' => '+5493875550000',
            'checkin' => $this->checkin()->toDateString(),
            'checkout' => $this->checkin()->addDays(2)->toDateString(),
            'adults' => 2,
            'message' => '¿Tienen lugar para dos con desayuno?',
            ...$overrides,
        ];
    }

    private function accountUser(): User
    {
        return User::create([
            'name' => 'user '.uniqid(),
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $this->account->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function availability(array $extra = []): array
    {
        return $this->tool('check_availability', [
            'checkin' => $this->checkin()->toDateString(),
            'checkout' => $this->checkin()->addDays(2)->toDateString(),
            'adults' => 2,
            ...$extra,
        ]);
    }

    private function checkin(): CarbonImmutable
    {
        return CarbonImmutable::today()->addMonth();
    }

    /**
     * Llama a una herramienta y devuelve el JSON de su resultado.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(string $name, array $arguments = []): array
    {
        $result = $this->callTool($name, $arguments);

        $this->assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');

        return json_decode($result['content'][0]['text'], true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        return $this->mcp('tools/call', ['name' => $name, 'arguments' => (object) $arguments])->assertOk()->json('result');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function mcp(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp/accommodation', $this->rpc($method, $params), $this->headers());
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->key}",
            'Accept' => 'application/json, text/event-stream',
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params];
    }

    private function accommodation(string $name): Accommodation
    {
        return Accommodation::create([
            'account_id' => $this->account->id,
            'plan_id' => $this->plan->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'enabled' => 1,
        ]);
    }
}
