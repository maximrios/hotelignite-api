<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Client;
use App\Models\ClientApiKey;
use App\Models\PoiCategory;
use App\Models\PointOfInterest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Puntos de interés: CRUD del catálogo, taxonomía y "En los alrededores"
 * (docs/points-of-interest-plan.md).
 *
 * Las distancias se arman desde la plaza 9 de Julio de Salta corriéndose al
 * norte: 1° de latitud ≈ 111.320 m, así que `metersNorth(500)` cae a ~500 m.
 *
 * Usa DatabaseTransactions (no RefreshDatabase) como el resto de la suite: la
 * base de testing trae datos reales que migrate:fresh borraría.
 */
class PointOfInterestTest extends TestCase
{
    use DatabaseTransactions;

    private const LAT = -24.7883;

    private const LNG = -65.4106;

    private User $platform;

    private int $cityId;

    private PoiCategory $food;

    private PoiCategory $restaurant;

    private PoiCategory $transport;

    private PoiCategory $airport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = $this->makeUser(User::TYPE_PLATFORM);
        $this->cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $this->food = $this->makeCategory(['default_radius_m' => 1000, 'sort_order' => 0]);
        $this->restaurant = $this->makeCategory(['parent_id' => $this->food->id]);
        $this->transport = $this->makeCategory(['default_radius_m' => 5000, 'sort_order' => 1]);
        $this->airport = $this->makeCategory(['parent_id' => $this->transport->id, 'nearest_limit' => 2]);
    }

    private function makeUser(string $type, ?Account $account = null): User
    {
        if ($type === User::TYPE_ACCOUNT && $account === null) {
            $account = Account::create(['name' => 'Cuenta test POI']);
        }

        return User::create([
            'name' => 'Test '.$type,
            'email' => $type.'-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => $type,
            'account_id' => $account?->id,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeCategory(array $overrides = []): PoiCategory
    {
        return PoiCategory::create(array_merge([
            'slug' => 'test-'.uniqid(),
            'name' => 'Categoría '.uniqid(),
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function makePoi(array $overrides = []): PointOfInterest
    {
        return PointOfInterest::create(array_merge([
            'city_id' => $this->cityId,
            'poi_category_id' => $this->restaurant->id,
            'name' => 'Punto '.uniqid(),
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ], $overrides));
    }

    private function metersNorth(int $meters): float
    {
        return round(self::LAT + $meters / 111320, 6);
    }

    private function makeAccommodation(?Account $account = null, string $lat = '-24.7883', string $lng = '-65.4106'): Accommodation
    {
        return Accommodation::create([
            'account_id' => ($account ?? Account::create(['name' => 'Cuenta aloj POI']))->id,
            'city_id' => $this->cityId,
            'name' => 'Aloj '.uniqid(),
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'city_id' => $this->cityId,
            'poi_category_id' => $this->restaurant->id,
            'name' => 'Cabildo de Salta',
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ], $overrides);
    }

    // --- accommodations.location -------------------------------------------

    public function test_la_location_del_alojamiento_se_deriva_de_las_coordenadas(): void
    {
        $accommodation = $this->makeAccommodation();

        $point = DB::selectOne(
            'SELECT ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng FROM accommodations WHERE id = ?',
            [$accommodation->id]
        );

        $this->assertEqualsWithDelta(self::LAT, $point->lat, 0.000001);
        $this->assertEqualsWithDelta(self::LNG, $point->lng, 0.000001);
    }

    public function test_coordenadas_invalidas_dejan_la_location_en_null_sin_fallar(): void
    {
        foreach ([['0', '0'], ['123123', '123123'], ['-24.79', '-195.41'], ['', ''], ['abc', '-65']] as [$lat, $lng]) {
            $accommodation = $this->makeAccommodation(null, $lat, $lng);

            $this->assertNull(
                DB::table('accommodations')->where('id', $accommodation->id)->value('location'),
                "lat={$lat} lng={$lng} debería dejar location en NULL"
            );
        }
    }

    public function test_la_location_no_sale_en_el_json_del_modelo(): void
    {
        $this->assertArrayNotHasKey('location', $this->makeAccommodation()->fresh()->toArray());
        $this->assertArrayNotHasKey('location', $this->makePoi()->fresh()->toArray());
    }

    // --- Acceso -------------------------------------------------------------

    public function test_un_account_lee_pero_no_escribe_el_catalogo(): void
    {
        $poi = $this->makePoi();
        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT));

        $this->getJson('/api/admin/v1/points-of-interest')->assertOk();
        $this->getJson("/api/admin/v1/points-of-interest/{$poi->id}")->assertOk();
        $this->getJson('/api/admin/v1/poi-categories')->assertOk();

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload())->assertForbidden();
        $this->patchJson("/api/admin/v1/points-of-interest/{$poi->id}", ['name' => 'Otro'])->assertForbidden();
        $this->deleteJson("/api/admin/v1/points-of-interest/{$poi->id}")->assertForbidden();
        $this->postJson('/api/admin/v1/poi-categories', ['slug' => 'x-'.uniqid(), 'name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/admin/v1/poi-categories/{$this->food->id}")->assertForbidden();
    }

    public function test_un_client_no_escribe_el_catalogo(): void
    {
        $poi = $this->makePoi();
        Sanctum::actingAs($this->makeUser(User::TYPE_CLIENT));

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload())->assertForbidden();
        $this->deleteJson("/api/admin/v1/points-of-interest/{$poi->id}")->assertForbidden();
    }

    public function test_sin_sesion_es_401(): void
    {
        $this->getJson('/api/admin/v1/points-of-interest')->assertUnauthorized();
    }

    // --- CRUD de puntos -----------------------------------------------------

    public function test_alta_devuelve_201_con_slug_categoria_y_ciudad(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload([
            'is_featured' => true,
            'website' => 'https://example.com',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cabildo de Salta')
            ->assertJsonPath('data.slug', 'cabildo-de-salta')
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.category.id', $this->restaurant->id)
            ->assertJsonPath('data.category.parent.id', $this->food->id)
            ->assertJsonPath('data.city.id', $this->cityId)
            ->assertJsonMissingPath('data.location');
    }

    public function test_el_slug_repetido_recibe_sufijo(): void
    {
        $this->makePoi(['name' => 'Cabildo de Salta']);
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'cabildo-de-salta-2');
    }

    public function test_la_categoria_tiene_que_ser_una_hoja(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload(['poi_category_id' => $this->food->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('poi_category_id');
    }

    public function test_media_coordenada_no_es_valida(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload(['longitude' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload(['latitude' => 91]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');
    }

    public function test_se_admite_un_punto_sin_coordenadas(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload(['latitude' => null, 'longitude' => null]))
            ->assertCreated()
            ->assertJsonPath('data.latitude', null);
    }

    public function test_external_id_es_unico_por_fuente(): void
    {
        $this->makePoi(['source' => 'osm', 'external_id' => 'node/123']);
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload(['source' => 'osm', 'external_id' => 'node/123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('external_id');

        $this->postJson('/api/admin/v1/points-of-interest', $this->payload(['source' => 'google', 'external_id' => 'node/123']))
            ->assertCreated();
    }

    public function test_edicion_parcial_mueve_la_location(): void
    {
        $poi = $this->makePoi();
        Sanctum::actingAs($this->platform);

        $this->patchJson("/api/admin/v1/points-of-interest/{$poi->id}", ['latitude' => -24.8, 'longitude' => -65.42])
            ->assertOk()
            ->assertJsonPath('data.latitude', -24.8)
            ->assertJsonPath('data.name', $poi->name);

        $lat = DB::selectOne('SELECT ST_Y(location::geometry) AS lat FROM points_of_interest WHERE id = ?', [$poi->id])->lat;
        $this->assertEqualsWithDelta(-24.8, $lat, 0.000001);
    }

    public function test_baja_es_logica(): void
    {
        $poi = $this->makePoi();
        Sanctum::actingAs($this->platform);

        $this->deleteJson("/api/admin/v1/points-of-interest/{$poi->id}")->assertNoContent();

        $this->assertSoftDeleted('points_of_interest', ['id' => $poi->id]);
        $this->getJson("/api/admin/v1/points-of-interest/{$poi->id}")->assertNotFound();
    }

    public function test_el_listado_filtra_por_categoria_raiz_y_busca_sin_acentos(): void
    {
        $cafe = $this->makePoi(['name' => 'Café del Tiempo '.uniqid()]);
        $other = $this->makePoi(['name' => 'Otro '.uniqid(), 'poi_category_id' => $this->airport->id]);
        Sanctum::actingAs($this->platform);

        $ids = collect($this->getJson("/api/admin/v1/points-of-interest?category_id={$this->food->id}&per_page=100")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to']])
            ->json('data'))->pluck('id');

        $this->assertContains($cafe->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $this->getJson('/api/admin/v1/points-of-interest?q=cafe+del+tiempo')
            ->assertOk()
            ->assertJsonPath('data.0.id', $cafe->id);
    }

    // --- Taxonomía ----------------------------------------------------------

    public function test_el_arbol_trae_raices_con_sus_hojas(): void
    {
        $this->makePoi();
        Sanctum::actingAs($this->platform);

        $root = collect($this->getJson('/api/admin/v1/poi-categories')->assertOk()->json('data'))
            ->firstWhere('id', $this->food->id);

        $this->assertNotNull($root);
        $this->assertSame($this->restaurant->id, $root['children'][0]['id']);
        $this->assertSame(1, $root['children'][0]['points_count']);
    }

    public function test_no_hay_tercer_nivel(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/poi-categories', [
            'slug' => 'nieta-'.uniqid(),
            'name' => 'Nieta',
            'parent_id' => $this->restaurant->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_una_raiz_con_hijas_no_puede_pasar_a_hoja(): void
    {
        Sanctum::actingAs($this->platform);

        $this->patchJson("/api/admin/v1/poi-categories/{$this->food->id}", ['parent_id' => $this->transport->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_una_hoja_con_puntos_no_puede_pasar_a_raiz(): void
    {
        $this->makePoi();
        Sanctum::actingAs($this->platform);

        $this->patchJson("/api/admin/v1/poi-categories/{$this->restaurant->id}", ['parent_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_borrar_una_categoria_en_uso_es_409(): void
    {
        $poi = $this->makePoi();
        $poi->delete();
        Sanctum::actingAs($this->platform);

        $this->deleteJson("/api/admin/v1/poi-categories/{$this->food->id}")->assertStatus(409);
        // Aunque el punto esté dado de baja: la FK lo sigue viendo.
        $this->deleteJson("/api/admin/v1/poi-categories/{$this->restaurant->id}")->assertStatus(409);

        $empty = $this->makeCategory(['parent_id' => $this->food->id]);
        $this->deleteJson("/api/admin/v1/poi-categories/{$empty->id}")->assertNoContent();
    }

    // --- En los alrededores -------------------------------------------------

    public function test_cercanos_ordena_por_distancia_y_corta_por_radio(): void
    {
        $account = Account::create(['name' => 'Cuenta cercanos']);
        $accommodation = $this->makeAccommodation($account);

        $far = $this->makePoi(['latitude' => $this->metersNorth(800)]);
        $near = $this->makePoi(['latitude' => $this->metersNorth(200)]);
        $outside = $this->makePoi(['latitude' => $this->metersNorth(1500)]);
        $noLocation = $this->makePoi(['latitude' => null, 'longitude' => null]);
        $disabled = $this->makePoi(['latitude' => $this->metersNorth(100), 'enabled' => false]);

        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT, $account));

        $response = $this->getJson("/api/admin/v1/accommodations/{$accommodation->id}/nearby-points")
            ->assertOk()
            ->assertJsonPath('data.has_location', true)
            ->assertJsonPath('data.origin.latitude', self::LAT)
            ->assertJsonPath('data.origin.longitude', self::LNG);

        $group = collect($response->json('data.groups'))->firstWhere('category.id', $this->food->id);
        $ids = collect($group['items'])->pluck('id')->all();

        $this->assertSame([$near->id, $far->id], $ids);
        $this->assertNotContains($outside->id, $ids);
        $this->assertNotContains($noLocation->id, $ids);
        $this->assertNotContains($disabled->id, $ids);

        $this->assertEqualsWithDelta(200, $group['items'][0]['distance_m'], 5);
        // 200 m × 1,3 / 80 m/min = 3,25 → 4 min.
        $this->assertSame(4, $group['items'][0]['walk_min']);
    }

    public function test_la_hoja_puede_pisar_el_radio_de_la_raiz(): void
    {
        $accommodation = $this->makeAccommodation();
        $wide = $this->makeCategory(['parent_id' => $this->food->id, 'default_radius_m' => 3000]);
        $poi = $this->makePoi(['poi_category_id' => $wide->id, 'latitude' => $this->metersNorth(2000)]);
        Sanctum::actingAs($this->platform);

        $ids = collect($this->getJson("/api/admin/v1/accommodations/{$accommodation->id}/nearby-points")
            ->json('data.groups'))->flatMap(fn (array $g) => collect($g['items'])->pluck('id'));

        $this->assertContains($poi->id, $ids);
    }

    public function test_destacados_primero_y_tope_por_grupo(): void
    {
        $accommodation = $this->makeAccommodation();
        foreach ([100, 200, 300] as $meters) {
            $this->makePoi(['latitude' => $this->metersNorth($meters)]);
        }
        $featured = $this->makePoi(['latitude' => $this->metersNorth(900), 'is_featured' => true]);
        Sanctum::actingAs($this->platform);

        $group = collect($this->getJson("/api/admin/v1/accommodations/{$accommodation->id}/nearby-points?limit_per_group=2")
            ->assertOk()
            ->json('data.groups'))->firstWhere('category.id', $this->food->id);

        $this->assertCount(2, $group['items']);
        $this->assertSame($featured->id, $group['items'][0]['id']);
        // 900 m × 1,3 / 80 m/min = 14,6 → 15 min.
        $this->assertSame(15, $group['items'][0]['walk_min']);
    }

    public function test_aeropuertos_por_cercania_sin_radio(): void
    {
        $accommodation = $this->makeAccommodation();
        $closest = $this->makePoi(['poi_category_id' => $this->airport->id, 'latitude' => $this->metersNorth(9000)]);
        $second = $this->makePoi(['poi_category_id' => $this->airport->id, 'latitude' => $this->metersNorth(60000)]);
        $third = $this->makePoi(['poi_category_id' => $this->airport->id, 'latitude' => $this->metersNorth(90000)]);
        Sanctum::actingAs($this->platform);

        $group = collect($this->getJson("/api/admin/v1/accommodations/{$accommodation->id}/nearby-points")
            ->json('data.groups'))->firstWhere('category.id', $this->transport->id);

        // nearest_limit = 2, y fuera del radio de 5 km de Transporte.
        $this->assertSame([$closest->id, $second->id], collect($group['items'])->pluck('id')->all());
        $this->assertNotContains($third->id, collect($group['items'])->pluck('id'));
    }

    public function test_alojamiento_sin_coordenadas_devuelve_grupos_vacios(): void
    {
        $accommodation = $this->makeAccommodation(null, '0', '0');
        $this->makePoi();
        Sanctum::actingAs($this->platform);

        $this->getJson("/api/admin/v1/accommodations/{$accommodation->id}/nearby-points")
            ->assertOk()
            ->assertJsonPath('data.has_location', false)
            ->assertJsonPath('data.origin', null)
            ->assertJsonPath('data.groups', []);
    }

    public function test_una_cuenta_no_ve_los_cercanos_de_otra(): void
    {
        $accommodation = $this->makeAccommodation();
        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT));

        $this->getJson("/api/admin/v1/accommodations/{$accommodation->id}/nearby-points")->assertForbidden();
    }

    // --- client API (portales) ------------------------------------------------

    public function test_el_client_ve_los_cercanos_de_sus_alojamientos_sin_campos_internos(): void
    {
        $client = Client::create(['name' => 'Portal test POI', 'type' => 'agency', 'active' => true]);
        $accommodation = $this->makeAccommodation();
        $accommodation->clients()->attach($client->id, ['status' => 'active']);
        $near = $this->makePoi(['latitude' => $this->metersNorth(200), 'source' => 'osm', 'external_id' => 'x-'.uniqid()]);
        $key = ClientApiKey::generateFor($client, 'poi test', ['catalog:read'])['plain'];

        $response = $this->getJson(
            "/api/client/v1/accommodations/{$accommodation->slug}/nearby-points",
            ['Authorization' => "Bearer {$key}"],
        )->assertOk()->assertJsonPath('data.has_location', true);

        $group = collect($response->json('data.groups'))->firstWhere('category.slug', $this->food->slug);
        $item = $group['items'][0];

        $this->assertSame($near->id, $item['id']);
        $this->assertSame($this->restaurant->slug, $item['category']['slug']);
        $this->assertEqualsWithDelta(200, $item['distance_m'], 5);
        $this->assertArrayNotHasKey('source', $item);
        $this->assertArrayNotHasKey('external_id', $item);
    }

    public function test_el_client_no_ve_los_cercanos_de_un_alojamiento_ajeno(): void
    {
        $client = Client::create(['name' => 'Portal ajeno POI', 'type' => 'agency', 'active' => true]);
        $accommodation = $this->makeAccommodation();
        $key = ClientApiKey::generateFor($client, 'poi test', ['catalog:read'])['plain'];

        $this->getJson(
            "/api/client/v1/accommodations/{$accommodation->slug}/nearby-points",
            ['Authorization' => "Bearer {$key}"],
        )->assertNotFound();
    }
}
