<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Client;
use App\Models\Experience;
use App\Models\ExperienceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Experiencias por `admin/v1` (docs/experiences-plan.md): sólo el staff lee y
 * escribe, las asigna a un client, y nacen en borrador.
 */
class ExperienceTest extends TestCase
{
    use DatabaseTransactions;

    private User $platform;

    private int $cityId;

    private int $otherCityId;

    private ExperienceCategory $category;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = $this->makeUser(User::TYPE_PLATFORM);
        $this->cityId = DB::table('cities')->insertGetId(['name' => 'Salta test', 'slug' => 'salta-test-'.uniqid()]);
        $this->otherCityId = DB::table('cities')->insertGetId(['name' => 'Cafayate test', 'slug' => 'cafayate-test-'.uniqid()]);
        $this->category = ExperienceCategory::create(['slug' => 'test-'.uniqid(), 'name' => 'Categoría test']);
        $this->client = Client::create(['name' => 'Portal test', 'type' => Client::TYPE_GOVERNMENT]);
    }

    private function makeUser(string $type, ?Client $client = null): User
    {
        return User::create([
            'name' => 'Test '.$type,
            'email' => $type.'-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => $type,
            'account_id' => $type === User::TYPE_ACCOUNT ? Account::create(['name' => 'Cuenta test'])->id : null,
            'client_id' => $client?->id,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeExperience(array $overrides = []): Experience
    {
        $clientId = $overrides['client_id'] ?? null;
        unset($overrides['client_id']);

        $experience = new Experience(array_merge([
            'experience_category_id' => $this->category->id,
            'departure_city_id' => $this->cityId,
            'name' => 'Experiencia '.uniqid(),
        ], $overrides));
        $experience->client_id = $clientId;
        $experience->save();

        return $experience;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'experience_category_id' => $this->category->id,
            'departure_city_id' => $this->cityId,
            'name' => 'Quebrada de las Conchas',
        ], $overrides);
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();
    }

    // --- Acceso -------------------------------------------------------------

    public function test_ni_un_account_ni_un_client_usan_admin(): void
    {
        $experience = $this->makeExperience(['client_id' => $this->client->id]);

        foreach ([$this->makeUser(User::TYPE_ACCOUNT), $this->makeUser(User::TYPE_CLIENT, $this->client)] as $user) {
            Sanctum::actingAs($user);

            $this->getJson('/api/admin/v1/experience-categories')->assertOk();
            $this->getJson('/api/admin/v1/experiences')->assertForbidden();
            $this->getJson("/api/admin/v1/experiences/{$experience->id}")->assertForbidden();
            $this->postJson('/api/admin/v1/experiences', $this->payload())->assertForbidden();
            $this->patchJson("/api/admin/v1/experiences/{$experience->id}", ['name' => 'Otro'])->assertForbidden();
            $this->deleteJson("/api/admin/v1/experiences/{$experience->id}")->assertForbidden();
            $this->postJson("/api/admin/v1/experiences/{$experience->id}/media", [
                'type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
            ])->assertForbidden();
        }
    }

    // --- CRUD ---------------------------------------------------------------

    public function test_alta_completa_asignada_a_un_client(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/experiences', $this->payload([
            'client_id' => $this->client->id,
            'city_ids' => [$this->otherCityId, $this->cityId],
            'duration_value' => 8,
            'duration_unit' => 'hours',
            'difficulty' => 'easy',
            'group_min' => 2,
            'group_max' => 15,
            'languages' => ['es', 'en'],
            'includes' => ['Traslado', '  ', 'Guía'],
            'operating_days' => [6, 2, 4],
            'season_months' => [],
            'price_type' => 'paid',
            'price_from' => 45000,
            'operator_name' => 'Guía Local',
            'links' => [['type' => 'instagram', 'url' => 'https://www.instagram.com/guialocal']],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'quebrada-de-las-conchas')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.client.id', $this->client->id)
            ->assertJsonPath('data.departure_city.id', $this->cityId)
            // La salida no se repite entre los destinos.
            ->assertJsonCount(1, 'data.cities')
            ->assertJsonPath('data.cities.0.id', $this->otherCityId)
            ->assertJsonPath('data.duration_kind', 'dia')
            ->assertJsonPath('data.includes', ['Traslado', 'Guía'])
            ->assertJsonPath('data.operating_days', [2, 4, 6])
            ->assertJsonPath('data.season_months', null)
            ->assertJsonPath('data.price_from', 45000)
            ->assertJsonPath('data.price_unit', 'person')
            ->assertJsonMissingPath('data.location');
    }

    public function test_sin_client_queda_del_staff(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/experiences', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.client_id', null)
            ->assertJsonPath('data.client', null);
    }

    public function test_reasignar_desasignar_y_publicar(): void
    {
        $other = Client::create(['name' => 'Otro portal', 'type' => Client::TYPE_AGENCY]);
        $experience = $this->makeExperience(['client_id' => $this->client->id]);
        Sanctum::actingAs($this->platform);

        $this->patchJson("/api/admin/v1/experiences/{$experience->id}", ['client_id' => $other->id, 'status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.client_id', $other->id)
            ->assertJsonPath('data.status', 'published');

        // Una edición que no menciona al client no lo toca.
        $this->patchJson("/api/admin/v1/experiences/{$experience->id}", ['name' => 'Nuevo nombre'])
            ->assertOk()
            ->assertJsonPath('data.client_id', $other->id);

        $this->patchJson("/api/admin/v1/experiences/{$experience->id}", ['client_id' => null])
            ->assertOk()
            ->assertJsonPath('data.client_id', null);
    }

    public function test_client_inexistente_o_dado_de_baja_es_invalido(): void
    {
        $gone = Client::create(['name' => 'De baja', 'type' => Client::TYPE_AGENCY]);
        $gone->delete();
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/experiences', $this->payload(['client_id' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $this->postJson('/api/admin/v1/experiences', $this->payload(['client_id' => $gone->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_validaciones_entre_campos_tambien_en_edicion_parcial(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/experiences', $this->payload(['group_min' => 10, 'group_max' => 5]))
            ->assertUnprocessable()->assertJsonValidationErrors('group_max');
        $this->postJson('/api/admin/v1/experiences', $this->payload(['duration_value' => 1.5, 'duration_unit' => 'days']))
            ->assertUnprocessable()->assertJsonValidationErrors('duration_value');
        $this->postJson('/api/admin/v1/experiences', $this->payload(['duration_value' => 3]))
            ->assertUnprocessable()->assertJsonValidationErrors('duration_unit');
        $this->postJson('/api/admin/v1/experiences', $this->payload(['operating_days' => [8]]))
            ->assertUnprocessable()->assertJsonValidationErrors('operating_days.0');
        $this->postJson('/api/admin/v1/experiences', $this->payload(['languages' => ['español']]))
            ->assertUnprocessable()->assertJsonValidationErrors('languages.0');
        $this->postJson('/api/admin/v1/experiences', $this->payload([
            'links' => [['type' => 'instagram', 'url' => 'https://phishing.example.com/instagram.com']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('links.0.url');

        $experience = $this->makeExperience(['group_min' => 4]);
        $this->patchJson("/api/admin/v1/experiences/{$experience->id}", ['group_max' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('group_max');
    }

    public function test_el_precio_solo_queda_si_es_pago(): void
    {
        $experience = $this->makeExperience(['price_type' => 'paid', 'price_from' => 1000]);
        Sanctum::actingAs($this->platform);

        $this->patchJson("/api/admin/v1/experiences/{$experience->id}", ['price_type' => 'free'])
            ->assertOk()
            ->assertJsonPath('data.price_from', null);
    }

    public function test_cambiar_la_salida_la_saca_de_los_destinos(): void
    {
        $experience = $this->makeExperience();
        $experience->cities()->sync([$this->otherCityId]);
        Sanctum::actingAs($this->platform);

        $this->patchJson("/api/admin/v1/experiences/{$experience->id}", ['departure_city_id' => $this->otherCityId])
            ->assertOk()
            ->assertJsonPath('data.departure_city_id', $this->otherCityId)
            ->assertJsonCount(0, 'data.cities');
    }

    public function test_baja_logica(): void
    {
        $experience = $this->makeExperience();
        Sanctum::actingAs($this->platform);

        $this->deleteJson("/api/admin/v1/experiences/{$experience->id}")->assertNoContent();
        $this->assertSoftDeleted('experiences', ['id' => $experience->id]);
    }

    // --- Filtros ------------------------------------------------------------

    public function test_filtra_por_client_ciudad_estado_y_duracion(): void
    {
        $mine = $this->makeExperience(['client_id' => $this->client->id, 'status' => 'published', 'duration_value' => 3, 'duration_unit' => 'hours']);
        $staff = $this->makeExperience(['duration_value' => 3, 'duration_unit' => 'days']);
        $visiting = $this->makeExperience(['departure_city_id' => $this->otherCityId, 'duration_value' => 8, 'duration_unit' => 'hours']);
        $visiting->cities()->sync([$this->cityId]);
        $elsewhere = $this->makeExperience(['departure_city_id' => $this->otherCityId]);
        Sanctum::actingAs($this->platform);

        $this->assertSame([$mine->id], $this->ids("/api/admin/v1/experiences?client_id={$this->client->id}"));
        $this->assertNotContains($mine->id, $this->ids('/api/admin/v1/experiences?client_id=none&per_page=100'));
        $this->assertContains($staff->id, $this->ids('/api/admin/v1/experiences?client_id=none&per_page=100'));

        // Sale de la ciudad o la recorre.
        $inCity = $this->ids("/api/admin/v1/experiences?city_id={$this->cityId}&per_page=100");
        $this->assertEqualsCanonicalizing([$mine->id, $staff->id, $visiting->id], $inCity);
        $this->assertNotContains($elsewhere->id, $inCity);

        $this->assertSame([$mine->id], $this->ids("/api/admin/v1/experiences?city_id={$this->cityId}&status=published"));
        $this->assertSame([$mine->id], $this->ids("/api/admin/v1/experiences?city_id={$this->cityId}&duration_kind=medio"));
        $this->assertSame([$visiting->id], $this->ids("/api/admin/v1/experiences?city_id={$this->cityId}&duration_kind=dia"));
        $this->assertSame([$staff->id], $this->ids("/api/admin/v1/experiences?city_id={$this->cityId}&duration_kind=varios"));
    }

    public function test_tipo_de_duracion(): void
    {
        $kind = fn (?float $value, ?string $unit) => (new Experience(['duration_value' => $value, 'duration_unit' => $unit]))->durationKind();

        $this->assertSame('medio', $kind(5, 'hours'));
        $this->assertSame('dia', $kind(5.5, 'hours'));
        $this->assertSame('dia', $kind(1, 'days'));
        $this->assertSame('varios', $kind(30, 'hours'));
        $this->assertSame('varios', $kind(2, 'days'));
        $this->assertNull($kind(null, null));
    }

    // --- Multimedia ---------------------------------------------------------

    public function test_galeria_con_imagen_video_y_reordenamiento(): void
    {
        $experience = $this->makeExperience();
        Sanctum::actingAs($this->platform);

        $image = $this->postJson("/api/admin/v1/experiences/{$experience->id}/media", [
            'type' => 'image',
            'url' => 'https://res.cloudinary.com/demo/image/upload/v1/conchas.jpg',
        ])->assertCreated()->assertJsonPath('data.provider', 'cloudinary')->json('data.id');

        $video = $this->postJson("/api/admin/v1/experiences/{$experience->id}/media", [
            'type' => 'video',
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertCreated()->assertJsonPath('data.provider_id', 'dQw4w9WgXcQ')->json('data.id');

        $this->patchJson("/api/admin/v1/experiences/{$experience->id}/media/reorder", ['ids' => [$video, $image]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $video);

        $this->getJson("/api/admin/v1/experiences/{$experience->id}")
            ->assertJsonCount(2, 'data.media')
            ->assertJsonPath('data.media.0.id', $video);

        // Media de otra experiencia no se borra desde ésta.
        $other = $this->makeExperience();
        $this->deleteJson("/api/admin/v1/experiences/{$other->id}/media/{$image}")->assertNotFound();
        $this->deleteJson("/api/admin/v1/experiences/{$experience->id}/media/{$image}")->assertNoContent();
    }
}
