<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApiKey;
use App\Models\Experience;
use App\Models\ExperienceCategory;
use App\Models\PoiCategory;
use App\Models\PointOfInterest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Experiencias por `client/v1` (docs/experiences-plan.md): cada portal ve sólo
 * las suyas y publicadas, sin campos internos.
 */
class ClientExperienceApiTest extends TestCase
{
    use DatabaseTransactions;

    private int $cityId;

    private int $otherCityId;

    private ExperienceCategory $category;

    private Client $portal;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cityId = DB::table('cities')->insertGetId(['name' => 'Salta test', 'slug' => 'salta-test-'.uniqid()]);
        $this->otherCityId = DB::table('cities')->insertGetId(['name' => 'Cafayate test', 'slug' => 'cafayate-test-'.uniqid()]);
        $this->category = ExperienceCategory::create(['slug' => 'test-'.uniqid(), 'name' => 'Categoría test']);
        $this->portal = Client::create(['name' => 'Portal test', 'type' => Client::TYPE_GOVERNMENT, 'active' => true]);
        $this->key = ClientApiKey::generateFor($this->portal, 'experiences test', ['catalog:read'])['plain'];
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeExperience(?Client $client, array $overrides = []): Experience
    {
        $experience = new Experience(array_merge([
            'experience_category_id' => $this->category->id,
            'departure_city_id' => $this->cityId,
            'name' => 'Experiencia '.uniqid(),
            'status' => Experience::STATUS_PUBLISHED,
        ], $overrides));
        $experience->client_id = $client?->id;
        $experience->save();

        return $experience;
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->key}"];
    }

    /** @return list<int> */
    private function ids(string $query = ''): array
    {
        return collect($this->getJson("/api/client/v1/experiences{$query}", $this->auth())->assertOk()->json('data'))
            ->pluck('id')
            ->all();
    }

    public function test_sin_key_es_401(): void
    {
        $this->getJson('/api/client/v1/experiences')->assertUnauthorized();
    }

    public function test_solo_las_propias_y_publicadas(): void
    {
        $mine = $this->makeExperience($this->portal);
        $draft = $this->makeExperience($this->portal, ['status' => Experience::STATUS_DRAFT]);
        $other = $this->makeExperience(Client::create(['name' => 'Otro portal', 'type' => Client::TYPE_AGENCY]));
        $staff = $this->makeExperience(null);

        $this->assertSame([$mine->id], $this->ids());

        $this->getJson("/api/client/v1/experiences/{$mine->slug}", $this->auth())->assertOk();
        foreach ([$draft, $other, $staff] as $hidden) {
            $this->getJson("/api/client/v1/experiences/{$hidden->slug}", $this->auth())->assertNotFound();
        }
    }

    public function test_detalle_sin_campos_internos_y_con_ubicacion_del_punto_de_encuentro(): void
    {
        $root = PoiCategory::create(['slug' => 'raiz-'.uniqid(), 'name' => 'Raíz', 'default_radius_m' => 1000]);
        $leaf = PoiCategory::create(['slug' => 'hoja-'.uniqid(), 'name' => 'Hoja', 'parent_id' => $root->id]);
        $poi = PointOfInterest::create([
            'city_id' => $this->cityId,
            'poi_category_id' => $leaf->id,
            'name' => 'Catedral',
            'address' => 'España 590',
            'latitude' => -24.79,
            'longitude' => -65.41,
        ]);
        $experience = $this->makeExperience($this->portal, [
            'point_of_interest_id' => $poi->id,
            'duration_value' => 2,
            'duration_unit' => 'days',
            'price_type' => 'paid',
            'price_from' => 90000,
            'operator_name' => 'Guía local',
            'source' => 'osm',
            'external_id' => 'x-'.uniqid(),
        ]);
        $experience->cities()->sync([$this->otherCityId]);
        $experience->media()->create([
            'type' => 'video', 'provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'provider_id' => 'dQw4w9WgXcQ', 'order' => 0,
        ]);

        $data = $this->getJson("/api/client/v1/experiences/{$experience->slug}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.duration_kind', 'varios')
            ->assertJsonPath('data.price.from', 90000)
            ->assertJsonPath('data.price.unit', 'person')
            ->assertJsonPath('data.meeting_point.name', 'Catedral')
            ->assertJsonPath('data.meeting_point.latitude', -24.79)
            ->assertJsonPath('data.departure_city.id', $this->cityId)
            ->assertJsonPath('data.cities.0.id', $this->otherCityId)
            ->assertJsonPath('data.operator.name', 'Guía local')
            ->assertJsonPath('data.media.0.embed_url', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
            ->json('data');

        foreach (['client_id', 'status', 'source', 'external_id', 'created_at', 'location'] as $internal) {
            $this->assertArrayNotHasKey($internal, $data);
        }
    }

    public function test_filtra_por_ciudad_categoria_y_duracion(): void
    {
        $other = ExperienceCategory::create(['slug' => 'otra-'.uniqid(), 'name' => 'Otra']);
        $local = $this->makeExperience($this->portal, ['duration_value' => 3, 'duration_unit' => 'hours']);
        $visiting = $this->makeExperience($this->portal, [
            'departure_city_id' => $this->otherCityId,
            'experience_category_id' => $other->id,
            'duration_value' => 8,
            'duration_unit' => 'hours',
        ]);
        $visiting->cities()->sync([$this->cityId]);
        $elsewhere = $this->makeExperience($this->portal, ['departure_city_id' => $this->otherCityId]);

        $this->assertEqualsCanonicalizing([$local->id, $visiting->id], $this->ids("?city={$this->cityId}"));
        $this->assertNotContains($elsewhere->id, $this->ids("?city={$this->cityId}"));
        $this->assertSame([$visiting->id], $this->ids("?category={$other->slug}"));
        $this->assertSame([$local->id], $this->ids('?duration_kind=medio'));
        $this->getJson('/api/client/v1/experiences?duration_kind=semana', $this->auth())->assertUnprocessable();
    }

    public function test_las_destacadas_van_primero(): void
    {
        $plain = $this->makeExperience($this->portal, ['name' => 'Aaa']);
        $featured = $this->makeExperience($this->portal, ['name' => 'Zzz', 'is_featured' => true]);

        $this->assertSame([$featured->id, $plain->id], $this->ids());
    }
}
