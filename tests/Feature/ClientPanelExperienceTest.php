<?php

namespace Tests\Feature;

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
 * Experiencias que carga un client por `client-panel/v1`
 * (docs/experiences-plan.md): el portal sale del token, ve y edita sólo lo
 * suyo, y puede publicar y destacar en su portal.
 */
class ClientPanelExperienceTest extends TestCase
{
    use DatabaseTransactions;

    private int $cityId;

    private ExperienceCategory $category;

    private Client $clientA;

    private Client $clientB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cityId = DB::table('cities')->insertGetId(['name' => 'Salta test', 'slug' => 'salta-test-'.uniqid()]);
        $this->category = ExperienceCategory::create(['slug' => 'test-'.uniqid(), 'name' => 'Categoría test']);
        $this->clientA = Client::create(['name' => 'Municipio A', 'type' => Client::TYPE_GOVERNMENT]);
        $this->clientB = Client::create(['name' => 'Agencia B', 'type' => Client::TYPE_AGENCY]);
        $this->userA = User::create([
            'name' => 'Client A',
            'email' => 'client-a-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $this->clientA->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'experience_category_id' => $this->category->id,
            'departure_city_id' => $this->cityId,
            'name' => 'Cabalgata '.uniqid(),
        ], $overrides);
    }

    private function experienceOf(Client $client): Experience
    {
        $experience = new Experience([
            'experience_category_id' => $this->category->id,
            'departure_city_id' => $this->cityId,
            'name' => 'Ajena '.uniqid(),
        ]);
        $experience->client_id = $client->id;
        $experience->save();

        return $experience;
    }

    public function test_crea_para_su_portal_aunque_mande_otro_client(): void
    {
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/client-panel/v1/experiences', $this->payload([
            'client_id' => $this->clientB->id,
            'source' => 'osm',
            'external_id' => 'x-1',
            'status' => 'published',
            'is_featured' => true,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.client_id', $this->clientA->id)
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.external_id', null)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_featured', true);
    }

    public function test_lista_y_edita_solo_lo_suyo(): void
    {
        Sanctum::actingAs($this->userA);
        $mine = $this->postJson('/api/client-panel/v1/experiences', $this->payload())->assertCreated()->json('data.id');
        $foreign = $this->experienceOf($this->clientB);

        $ids = collect($this->getJson('/api/client-panel/v1/experiences')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$mine], $ids);

        $this->getJson("/api/client-panel/v1/experiences/{$foreign->id}")->assertForbidden();
        // Dueño antes que validación: dato inválido sobre algo ajeno es 403, no 422.
        $this->patchJson("/api/client-panel/v1/experiences/{$foreign->id}", ['group_max' => -5])->assertForbidden();
        $this->deleteJson("/api/client-panel/v1/experiences/{$foreign->id}")->assertForbidden();
        $this->postJson("/api/client-panel/v1/experiences/{$foreign->id}/media", [
            'type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertForbidden();

        $this->patchJson("/api/client-panel/v1/experiences/{$mine}", ['status' => 'published', 'client_id' => $this->clientB->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.client_id', $this->clientA->id);

        $this->postJson("/api/client-panel/v1/experiences/{$mine}/media", [
            'type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertCreated();

        $this->deleteJson("/api/client-panel/v1/experiences/{$mine}")->assertNoContent();
    }

    public function test_un_client_sigue_sin_escribir_por_admin(): void
    {
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/admin/v1/experiences', $this->payload())->assertForbidden();
        $this->getJson('/api/admin/v1/experience-categories')->assertOk();
    }
}
