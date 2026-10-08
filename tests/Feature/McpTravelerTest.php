<?php

namespace Tests\Feature;

use App\AI\Support\AiContext;
use App\AI\Support\Mock\MockInventory;
use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Client;
use App\Models\ClientApiKey;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomAvailability;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MCP del viajero (/mcp/traveler): autenticación, tenencia y la semántica de
 * disponibilidad de tres estados con y sin mocks. Ver docs/mcp-traveler-plan.md.
 *
 * Usa DatabaseTransactions por el mismo motivo que AccommodationTenancyTest:
 * el schema real viene de un dump.
 */
class McpTravelerTest extends TestCase
{
    use DatabaseTransactions;

    private Client $clientA;

    private Accommodation $accA;

    private Accommodation $accB;

    private string $keyA;

    private string $citySlug;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.mock_missing_data' => false]);

        $this->clientA = Client::create(['name' => 'Client A test', 'type' => 'agency', 'active' => true]);
        $clientB = Client::create(['name' => 'Client B test', 'type' => 'agency', 'active' => true]);

        $city = $this->anyCity();
        $this->citySlug = (string) $city->slug;

        $this->accA = $this->accommodation('Hotel A test', $city->id);
        $this->accB = $this->accommodation('Hotel B test', $city->id);

        $this->accA->clients()->attach($this->clientA->id, ['status' => 'active']);
        $this->accB->clients()->attach($clientB->id, ['status' => 'active']);

        $this->keyA = ClientApiKey::generateFor($this->clientA, 'mcp test', ['mcp:read'])['plain'];
    }

    #[Test]
    public function sin_credencial_responde_401(): void
    {
        $this->postJson('/mcp/traveler', $this->rpc('tools/list'))->assertStatus(401);
    }

    #[Test]
    public function una_key_sin_ability_mcp_read_responde_403(): void
    {
        $key = ClientApiKey::generateFor($this->clientA, 'bff test', ['catalog:read'])['plain'];

        $this->postJson('/mcp/traveler', $this->rpc('tools/list'), ['Authorization' => "Bearer {$key}"])
            ->assertStatus(403);
    }

    #[Test]
    public function una_key_revocada_responde_401(): void
    {
        ClientApiKey::where('client_id', $this->clientA->id)->update(['revoked_at' => now()]);

        $this->mcp('tools/list')->assertStatus(401);
    }

    #[Test]
    public function lista_las_cinco_herramientas(): void
    {
        $names = collect($this->mcp('tools/list')->assertOk()->json('result.tools'))->pluck('name')->sort()->values()->all();

        $this->assertSame([
            'check_availability',
            'get_accommodation_details',
            'list_destinations',
            'search_accommodations',
            'search_availability',
        ], $names);
    }

    #[Test]
    public function la_busqueda_no_muestra_alojamientos_de_otro_client(): void
    {
        $items = $this->tool('search_accommodations', ['destination' => $this->citySlug, 'query' => 'test']);
        $slugs = array_column($items['items'], 'slug');

        $this->assertContains($this->accA->slug, $slugs);
        $this->assertNotContains($this->accB->slug, $slugs);
    }

    #[Test]
    public function la_ficha_de_un_alojamiento_de_otro_client_es_un_error(): void
    {
        $result = $this->mcp('tools/call', ['name' => 'get_accommodation_details', 'arguments' => ['slug' => $this->accB->slug]])
            ->assertOk()
            ->json('result');

        $this->assertTrue($result['isError']);
    }

    #[Test]
    public function un_alojamiento_deshabilitado_no_aparece(): void
    {
        $this->accA->update(['enabled' => 0]);

        $items = $this->tool('search_accommodations', ['destination' => $this->citySlug, 'query' => 'test']);

        $this->assertNotContains($this->accA->slug, array_column($items['items'], 'slug'));
    }

    #[Test]
    public function sin_inventario_y_sin_mocks_la_disponibilidad_es_unknown(): void
    {
        $result = $this->availability();

        $this->assertSame('unknown', $result['status']);
        $this->assertSame('no_data', $result['reason']);
        $this->assertSame('real', $result['source']);
        $this->assertNull($result['price_from']);
    }

    #[Test]
    public function con_mocks_la_respuesta_sale_marcada_y_es_deterministica(): void
    {
        config(['ai.mock_missing_data' => true]);

        $first = $this->availability();
        $second = $this->availability();

        $this->assertSame('mock', $first['source']);
        $this->assertNotSame('unknown', $first['status']);
        $this->assertSame($first, $second);

        if ($first['status'] === 'available') {
            $this->assertSame('mock', $first['price_from']['source']);
        }
    }

    #[Test]
    public function en_produccion_los_mocks_se_ignoran(): void
    {
        config(['ai.mock_missing_data' => true]);
        $this->app['env'] = 'production';

        $this->assertFalse(MockInventory::enabled());
    }

    #[Test]
    public function ninguna_habitacion_alcanza_para_el_grupo(): void
    {
        $this->roomType(maxOccupancy: 2);

        $result = $this->availability(adults: 3);

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('capacity', $result['reason']);
        $this->assertSame([], $result['rooms']);
    }

    #[Test]
    public function con_inventario_real_abierto_esta_disponible_con_precio_real(): void
    {
        $roomType = $this->roomType(maxOccupancy: 2);
        $this->openNights($roomType, 2);

        $plan = RatePlan::create(['room_type_id' => $roomType->id, 'name' => 'Estándar', 'code' => 'STD', 'enabled' => true]);
        Rate::create(['rate_plan_id' => $plan->id, 'date' => $this->checkin()->toDateString(), 'price' => 8500000, 'currency' => 'ARS']);

        $result = $this->availability();

        $this->assertSame('available', $result['status']);
        $this->assertSame('real', $result['source']);
        // `rates.price` está en centavos.
        $this->assertSame(['amount' => 85000, 'currency' => 'ARS', 'per' => 'night', 'source' => 'real'], $result['price_from']);
    }

    #[Test]
    public function una_noche_cerrada_la_vuelve_no_disponible(): void
    {
        $roomType = $this->roomType(maxOccupancy: 2);
        $this->openNights($roomType, 2);
        RoomAvailability::where('room_type_id', $roomType->id)
            ->whereDate('date', $this->checkin()->addDay()->toDateString())
            ->update(['closed' => true]);

        $result = $this->availability();

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('no_availability', $result['reason']);
    }

    #[Test]
    public function el_idioma_sale_de_accept_language_con_fallback(): void
    {
        $this->assertSame('es', AiContext::resolveLanguage('en-US,es-AR;q=0.8'));
        $this->assertSame('es', AiContext::resolveLanguage('fr'));
        $this->assertSame('es', AiContext::resolveLanguage(null));

        config(['ai.languages' => ['es', 'en']]);

        $this->assertSame('en', AiContext::resolveLanguage('en-US,es-AR;q=0.8'));
    }

    // --- helpers ---

    /**
     * @return array<string, mixed>
     */
    private function availability(int $adults = 2): array
    {
        return $this->tool('check_availability', [
            'accommodation_id' => $this->accA->id,
            'checkin' => $this->checkin()->toDateString(),
            'checkout' => $this->checkin()->addDays(2)->toDateString(),
            'adults' => $adults,
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
    private function tool(string $name, array $arguments): array
    {
        $result = $this->mcp('tools/call', ['name' => $name, 'arguments' => $arguments])->assertOk()->json('result');

        $this->assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');

        return json_decode($result['content'][0]['text'], true);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function mcp(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp/traveler', $this->rpc($method, $params), [
            'Authorization' => "Bearer {$this->keyA}",
            'Accept' => 'application/json, text/event-stream',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params];
    }

    private function accommodation(string $name, int $cityId): Accommodation
    {
        return Accommodation::create([
            'account_id' => Account::create(['name' => "Cuenta {$name}"])->id,
            'city_id' => $cityId,
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'enabled' => 1,
        ]);
    }

    private function roomType(int $maxOccupancy): RoomType
    {
        return RoomType::create([
            'accommodation_id' => $this->accA->id,
            'slug' => 'doble-'.uniqid(),
            'max_occupancy' => $maxOccupancy,
            'quantity' => 1,
        ]);
    }

    private function openNights(RoomType $roomType, int $nights): void
    {
        for ($i = 0; $i < $nights; $i++) {
            RoomAvailability::create([
                'room_type_id' => $roomType->id,
                'date' => $this->checkin()->addDays($i)->toDateString(),
                'available' => 1,
                'total' => 1,
                'closed' => false,
            ]);
        }
    }

    /**
     * Mismo criterio que AccommodationTenancyTest: `cities` es legacy y sin
     * timestamps; si no hay ninguna, se inserta en crudo.
     */
    private function anyCity(): City
    {
        $city = City::query()->whereNotNull('slug')->where('slug', '!=', '')->first();

        if ($city !== null) {
            return $city;
        }

        $id = DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        return City::findOrFail($id);
    }
}
