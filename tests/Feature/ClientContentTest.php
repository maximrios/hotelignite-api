<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Client;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\PoiCategory;
use App\Models\PointOfInterest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contenido turístico cargado por clients B2B por `client-panel/v1`
 * (docs/events-plan.md): crean en cualquier ciudad, listan y editan sólo lo
 * propio, no fijan campos de curaduría y siguen sin escribir por `admin/v1`.
 */
class ClientContentTest extends TestCase
{
    use DatabaseTransactions;

    private int $cityId;

    private PoiCategory $leaf;

    private EventCategory $eventCategory;

    private Client $clientA;

    private Client $clientB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $root = PoiCategory::create(['slug' => 'raiz-'.uniqid(), 'name' => 'Raíz', 'default_radius_m' => 1000]);
        $this->leaf = PoiCategory::create(['slug' => 'hoja-'.uniqid(), 'name' => 'Hoja', 'parent_id' => $root->id]);
        $this->eventCategory = EventCategory::create(['slug' => 'ev-'.uniqid(), 'name' => 'Categoría']);

        $this->clientA = Client::create(['name' => 'Municipio A', 'type' => Client::TYPE_GOVERNMENT]);
        $this->clientB = Client::create(['name' => 'Cámara B', 'type' => Client::TYPE_AGENCY]);
        $this->userA = $this->clientUser($this->clientA);
    }

    private function clientUser(Client $client): User
    {
        return User::create([
            'name' => 'Client user',
            'email' => 'client-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => User::TYPE_CLIENT,
            'client_id' => $client->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function poiPayload(array $overrides = []): array
    {
        return array_merge([
            'city_id' => $this->cityId,
            'poi_category_id' => $this->leaf->id,
            'name' => 'Mirador '.uniqid(),
            'latitude' => -24.78,
            'longitude' => -65.41,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'city_id' => $this->cityId,
            'event_category_id' => $this->eventCategory->id,
            'name' => 'Peña '.uniqid(),
            'start_date' => '2031-03-07',
            'recurrence' => 'weekly',
            'weekdays' => [5],
        ], $overrides);
    }

    private function poiOf(?Client $client): PointOfInterest
    {
        $poi = new PointOfInterest($this->poiPayload());
        $poi->client_id = $client?->id;
        $poi->save();

        return $poi;
    }

    private function eventOf(?Client $client): Event
    {
        $event = new Event($this->eventPayload());
        $event->client_id = $client?->id;
        $event->save();

        return $event;
    }

    public function test_el_client_crea_un_punto_y_queda_como_suyo(): void
    {
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/client-panel/v1/points-of-interest', $this->poiPayload())
            ->assertCreated()
            ->assertJsonPath('data.client_id', $this->clientA->id)
            ->assertJsonPath('data.client.name', 'Municipio A');
    }

    public function test_el_client_no_fija_campos_de_curaduria_ni_el_dueno(): void
    {
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/client-panel/v1/points-of-interest', $this->poiPayload([
            'is_featured' => true,
            'source' => 'osm',
            'external_id' => 'node/1',
            'client_id' => $this->clientB->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.is_featured', false)
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.external_id', null)
            ->assertJsonPath('data.client_id', $this->clientA->id);

        $this->postJson('/api/client-panel/v1/events', $this->eventPayload(['is_featured' => true]))
            ->assertCreated()
            ->assertJsonPath('data.is_featured', false)
            ->assertJsonPath('data.client_id', $this->clientA->id);
    }

    public function test_el_listado_trae_solo_lo_propio(): void
    {
        $mine = $this->poiOf($this->clientA);
        $other = $this->poiOf($this->clientB);
        $staff = $this->poiOf(null);
        $myEvent = $this->eventOf($this->clientA);
        $otherEvent = $this->eventOf($this->clientB);
        Sanctum::actingAs($this->userA);

        $ids = collect($this->getJson('/api/client-panel/v1/points-of-interest?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertNotContains($staff->id, $ids);

        $ids = collect($this->getJson('/api/client-panel/v1/events?when=all&per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($myEvent->id, $ids);
        $this->assertNotContains($otherEvent->id, $ids);
    }

    public function test_no_edita_ni_borra_lo_ajeno_ni_lo_del_staff(): void
    {
        $other = $this->poiOf($this->clientB);
        $staff = $this->poiOf(null);
        $otherEvent = $this->eventOf($this->clientB);
        Sanctum::actingAs($this->userA);

        foreach ([$other, $staff] as $poi) {
            $this->getJson("/api/client-panel/v1/points-of-interest/{$poi->id}")->assertForbidden();
            $this->patchJson("/api/client-panel/v1/points-of-interest/{$poi->id}", ['name' => 'Pisado'])->assertForbidden();
            $this->deleteJson("/api/client-panel/v1/points-of-interest/{$poi->id}")->assertForbidden();
        }

        $this->patchJson("/api/client-panel/v1/events/{$otherEvent->id}", ['name' => 'Pisado'])->assertForbidden();
        // Aunque los datos sean inválidos: primero se chequea el dueño.
        $this->patchJson("/api/client-panel/v1/events/{$otherEvent->id}", ['name' => 'x'])->assertForbidden();
        $this->postJson("/api/client-panel/v1/events/{$otherEvent->id}/media", ['type' => 'video', 'url' => 'nope'])->assertForbidden();
        $this->deleteJson("/api/client-panel/v1/events/{$otherEvent->id}")->assertForbidden();
        $this->postJson("/api/client-panel/v1/events/{$otherEvent->id}/media", [
            'type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertForbidden();
    }

    public function test_edita_borra_y_carga_media_en_lo_propio(): void
    {
        $poi = $this->poiOf($this->clientA);
        $event = $this->eventOf($this->clientA);
        Sanctum::actingAs($this->userA);

        $this->patchJson("/api/client-panel/v1/points-of-interest/{$poi->id}", ['name' => 'Mirador del Cerro'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mirador del Cerro');

        $this->postJson("/api/client-panel/v1/events/{$event->id}/media", [
            'type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertCreated();

        $this->deleteJson("/api/client-panel/v1/events/{$event->id}")->assertNoContent();
        $this->deleteJson("/api/client-panel/v1/points-of-interest/{$poi->id}")->assertNoContent();
    }

    public function test_el_client_sigue_sin_escribir_por_admin(): void
    {
        $mine = $this->poiOf($this->clientA);
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/admin/v1/points-of-interest', $this->poiPayload())->assertForbidden();
        $this->patchJson("/api/admin/v1/points-of-interest/{$mine->id}", ['name' => 'X'])->assertForbidden();
        $this->postJson('/api/admin/v1/events', $this->eventPayload())->assertForbidden();
        // Leer el catálogo completo sí.
        $this->getJson('/api/admin/v1/points-of-interest')->assertOk();
    }

    public function test_el_staff_edita_lo_de_un_client(): void
    {
        $event = $this->eventOf($this->clientA);
        Sanctum::actingAs(User::create([
            'name' => 'Staff',
            'email' => 'staff-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => User::TYPE_PLATFORM,
        ]));

        $this->patchJson("/api/admin/v1/events/{$event->id}", ['is_featured' => true])
            ->assertOk()
            ->assertJsonPath('data.is_featured', true)
            // La curaduría del staff no le cambia el dueño.
            ->assertJsonPath('data.client_id', $this->clientA->id);
    }

    public function test_un_account_no_entra_al_panel_de_client(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Hotelero',
            'email' => 'acc-'.uniqid().'@test.local',
            'password' => Hash::make('password123'),
            'user_type' => User::TYPE_ACCOUNT,
        ]));

        $this->postJson('/api/client-panel/v1/points-of-interest', $this->poiPayload())->assertForbidden();
    }
}
