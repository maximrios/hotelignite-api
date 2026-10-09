<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Media;
use App\Models\PoiCategory;
use App\Models\PointOfInterest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Agenda de eventos (docs/events-plan.md): CRUD del staff, recurrencia semanal,
 * multimedia, links y "agenda cercana" de un alojamiento.
 *
 * Las fechas se arman relativas a un lunes fijo lejano (2031-03-03) para que los
 * días de la semana sean deterministas; las que dependen de "hoy" usan
 * `Event::today()`.
 */
class EventTest extends TestCase
{
    use DatabaseTransactions;

    private const LAT = -24.7883;

    private const LNG = -65.4106;

    /** Lunes. */
    private const MONDAY = '2031-03-03';

    private User $platform;

    private int $cityId;

    private EventCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = $this->makeUser(User::TYPE_PLATFORM);
        $this->cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);
        $this->category = EventCategory::create(['slug' => 'test-'.uniqid(), 'name' => 'Categoría test']);
    }

    private function makeUser(string $type, ?Account $account = null): User
    {
        if ($type === User::TYPE_ACCOUNT && $account === null) {
            $account = Account::create(['name' => 'Cuenta test eventos']);
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
    private function makeEvent(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'city_id' => $this->cityId,
            'event_category_id' => $this->category->id,
            'name' => 'Evento '.uniqid(),
            'start_date' => self::MONDAY,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'city_id' => $this->cityId,
            'event_category_id' => $this->category->id,
            'name' => 'Serenata a Cafayate',
            'start_date' => self::MONDAY,
            'end_date' => '2031-03-05',
            'start_time' => '21:00',
        ], $overrides);
    }

    private function metersNorth(int $meters): float
    {
        return round(self::LAT + $meters / 111320, 6);
    }

    private function makeAccommodation(?Account $account = null): Accommodation
    {
        return Accommodation::create([
            'account_id' => ($account ?? Account::create(['name' => 'Cuenta aloj eventos']))->id,
            'city_id' => $this->cityId,
            'name' => 'Aloj '.uniqid(),
            'latitude' => (string) self::LAT,
            'longitude' => (string) self::LNG,
        ]);
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();
    }

    // --- Acceso -------------------------------------------------------------

    public function test_un_account_lee_pero_no_escribe(): void
    {
        $event = $this->makeEvent();
        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT));

        $this->getJson('/api/admin/v1/events?when=all')->assertOk();
        $this->getJson("/api/admin/v1/events/{$event->id}")->assertOk();
        $this->getJson('/api/admin/v1/event-categories')->assertOk();
        $this->postJson('/api/admin/v1/events', $this->payload())->assertForbidden();
        $this->patchJson("/api/admin/v1/events/{$event->id}", ['name' => 'Otro'])->assertForbidden();
        $this->deleteJson("/api/admin/v1/events/{$event->id}")->assertForbidden();
        $this->postJson("/api/admin/v1/events/{$event->id}/media", [
            'type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertForbidden();
    }

    // --- CRUD ---------------------------------------------------------------

    public function test_alta_con_links_y_horario(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/events', $this->payload([
            'links' => [
                ['type' => 'instagram', 'url' => 'https://www.instagram.com/serenata'],
                ['type' => 'website', 'url' => 'https://serenata.example.com'],
            ],
            'price_type' => 'paid',
            'price_from' => 15000,
            'price_to' => 40000,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'serenata-a-cafayate')
            ->assertJsonPath('data.start_time', '21:00')
            ->assertJsonPath('data.end_date', '2031-03-05')
            ->assertJsonPath('data.recurrence', 'none')
            ->assertJsonPath('data.weekdays', null)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.links.0.type', 'instagram')
            ->assertJsonPath('data.client', null)
            ->assertJsonMissingPath('data.location');
    }

    public function test_un_link_que_no_es_de_su_red_es_invalido(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/events', $this->payload([
            'links' => [['type' => 'instagram', 'url' => 'https://phishing.example.com/instagram.com']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('links.0.url');
    }

    public function test_fin_anterior_al_inicio_es_invalido_tambien_en_edicion_parcial(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/events', $this->payload(['end_date' => '2031-03-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');

        $event = $this->makeEvent();
        $this->patchJson("/api/admin/v1/events/{$event->id}", ['end_date' => '2031-02-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    public function test_semanal_exige_dias_y_al_volver_a_none_se_limpian(): void
    {
        Sanctum::actingAs($this->platform);

        $this->postJson('/api/admin/v1/events', $this->payload(['recurrence' => 'weekly']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weekdays');

        // Días repetidos o fuera de 1..7.
        $this->postJson('/api/admin/v1/events', $this->payload(['recurrence' => 'weekly', 'weekdays' => [5, 5]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weekdays.0');
        $this->postJson('/api/admin/v1/events', $this->payload(['recurrence' => 'weekly', 'weekdays' => [8]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weekdays.0');

        $id = $this->postJson('/api/admin/v1/events', $this->payload([
            'recurrence' => 'weekly',
            'weekdays' => [5, 3],
            'end_date' => null,
        ]))->assertCreated()->assertJsonPath('data.weekdays', [3, 5])->json('data.id');

        $this->patchJson("/api/admin/v1/events/{$id}", ['recurrence' => 'none'])
            ->assertOk()
            ->assertJsonPath('data.weekdays', null);
    }

    public function test_baja_logica(): void
    {
        $event = $this->makeEvent();
        Sanctum::actingAs($this->platform);

        $this->deleteJson("/api/admin/v1/events/{$event->id}")->assertNoContent();
        $this->assertSoftDeleted('events', ['id' => $event->id]);
    }

    // --- Ocurrencias --------------------------------------------------------

    public function test_ventana_filtra_por_rango_y_por_dia_de_la_semana(): void
    {
        $single = $this->makeEvent(['start_date' => '2031-03-05']);                       // miércoles
        $range = $this->makeEvent(['start_date' => '2031-03-01', 'end_date' => '2031-03-10']);
        $fridays = $this->makeEvent(['recurrence' => 'weekly', 'weekdays' => [5], 'end_date' => null]);
        $sundays = $this->makeEvent(['recurrence' => 'weekly', 'weekdays' => [7], 'end_date' => '2031-06-30']);
        Sanctum::actingAs($this->platform);

        // Lunes a jueves: ni viernes ni domingos.
        $ids = $this->ids('/api/admin/v1/events?from=2031-03-03&to=2031-03-06&per_page=100');
        $this->assertContains($single->id, $ids);
        $this->assertContains($range->id, $ids);
        $this->assertNotContains($fridays->id, $ids);
        $this->assertNotContains($sundays->id, $ids);

        // Un fin de semana: viernes y domingo entran; el miércoles suelto no.
        $ids = $this->ids('/api/admin/v1/events?from=2031-03-07&to=2031-03-09&per_page=100');
        $this->assertContains($fridays->id, $ids);
        $this->assertContains($sundays->id, $ids);
        $this->assertContains($range->id, $ids);
        $this->assertNotContains($single->id, $ids);

        // Después del fin de la serie de domingos.
        $ids = $this->ids('/api/admin/v1/events?from=2031-07-01&to=2031-07-31&per_page=100');
        $this->assertContains($fridays->id, $ids);
        $this->assertNotContains($sundays->id, $ids);
    }

    public function test_upcoming_y_past_respecto_de_hoy(): void
    {
        $today = Event::today();
        $past = $this->makeEvent(['start_date' => $today->subDays(10)->toDateString()]);
        $ongoing = $this->makeEvent([
            'start_date' => $today->subDay()->toDateString(),
            'end_date' => $today->addDay()->toDateString(),
        ]);
        $openWeekly = $this->makeEvent([
            'start_date' => $today->subYear()->toDateString(),
            'recurrence' => 'weekly',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        Sanctum::actingAs($this->platform);

        $upcoming = $this->ids('/api/admin/v1/events?per_page=100');
        $this->assertNotContains($past->id, $upcoming);
        $this->assertContains($ongoing->id, $upcoming);
        $this->assertContains($openWeekly->id, $upcoming);

        $pastIds = $this->ids('/api/admin/v1/events?when=past&per_page=100');
        $this->assertContains($past->id, $pastIds);
        $this->assertNotContains($ongoing->id, $pastIds);

        $this->getJson("/api/admin/v1/events/{$ongoing->id}")
            ->assertJsonPath('data.next_date', $today->toDateString());
        $this->getJson("/api/admin/v1/events/{$past->id}")
            ->assertJsonPath('data.next_date', null);
    }

    public function test_proxima_ocurrencia_de_un_semanal(): void
    {
        $event = $this->makeEvent(['recurrence' => 'weekly', 'weekdays' => [5], 'end_date' => '2031-03-31']);

        $this->assertSame('2031-03-07', $event->nextOccurrence(CarbonImmutable::parse('2031-03-03'))?->toDateString());
        $this->assertSame('2031-03-07', $event->nextOccurrence(CarbonImmutable::parse('2031-03-07'))?->toDateString());
        $this->assertSame('2031-03-14', $event->nextOccurrence(CarbonImmutable::parse('2031-03-08'))?->toDateString());
        $this->assertNull($event->nextOccurrence(CarbonImmutable::parse('2031-03-29')));
    }

    // --- Multimedia ---------------------------------------------------------

    public function test_youtube_se_normaliza_y_no_se_aceptan_otros_videos(): void
    {
        $event = $this->makeEvent();
        Sanctum::actingAs($this->platform);

        $this->postJson("/api/admin/v1/events/{$event->id}/media", [
            'type' => 'video',
            'url' => 'https://youtube.com/shorts/dQw4w9WgXcQ?feature=share',
        ])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'youtube')
            ->assertJsonPath('data.provider_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertJsonPath('data.embed_url', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');

        $this->postJson("/api/admin/v1/events/{$event->id}/media", [
            'type' => 'video',
            'url' => 'https://vimeo.com/123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    public function test_reconoce_las_formas_de_url_de_youtube(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/live/dQw4w9WgXcQ',
        ] as $url) {
            $this->assertSame('dQw4w9WgXcQ', Media::youTubeId($url), $url);
        }

        $this->assertNull(Media::youTubeId('https://evil.example.com/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(Media::youTubeId('https://youtube.com/watch?v=corto'));
    }

    public function test_imagen_de_cloudinary_con_miniatura_y_reordenamiento(): void
    {
        $event = $this->makeEvent();
        Sanctum::actingAs($this->platform);

        $first = $this->postJson("/api/admin/v1/events/{$event->id}/media", [
            'type' => 'image',
            'url' => 'https://res.cloudinary.com/demo/image/upload/v1/hotelignite/events/a.jpg',
        ])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'cloudinary')
            ->assertJsonPath('data.thumbnail_url', 'https://res.cloudinary.com/demo/image/upload/c_fill,w_400,h_300/v1/hotelignite/events/a.jpg')
            ->json('data.id');
        $second = $this->postJson("/api/admin/v1/events/{$event->id}/media", [
            'type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->json('data.id');

        $this->patchJson("/api/admin/v1/events/{$event->id}/media/reorder", ['ids' => [$second, $first]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $second);

        // Un id ajeno o faltante no reordena.
        $this->patchJson("/api/admin/v1/events/{$event->id}/media/reorder", ['ids' => [$second]])
            ->assertUnprocessable();

        // La media de otro evento no se borra por esta ruta.
        $other = $this->makeEvent();
        $this->deleteJson("/api/admin/v1/events/{$other->id}/media/{$first}")->assertNotFound();
        $this->deleteJson("/api/admin/v1/events/{$event->id}/media/{$first}")->assertNoContent();
    }

    // --- Agenda cercana -----------------------------------------------------

    public function test_cercanos_por_distancia_ventana_y_sede(): void
    {
        $account = Account::create(['name' => 'Cuenta agenda']);
        $accommodation = $this->makeAccommodation($account);

        $poiCategory = PoiCategory::create(['slug' => 'raiz-'.uniqid(), 'name' => 'Raíz', 'default_radius_m' => 1000]);
        $leaf = PoiCategory::create(['slug' => 'hoja-'.uniqid(), 'name' => 'Hoja', 'parent_id' => $poiCategory->id]);
        $venue = PointOfInterest::create([
            'city_id' => $this->cityId,
            'poi_category_id' => $leaf->id,
            'name' => 'Teatro '.uniqid(),
            'latitude' => $this->metersNorth(3000),
            'longitude' => self::LNG,
        ]);

        $near = $this->makeEvent(['start_date' => '2031-03-04', 'latitude' => $this->metersNorth(500)]);
        $atVenue = $this->makeEvent(['start_date' => '2031-03-05', 'latitude' => null, 'longitude' => null, 'point_of_interest_id' => $venue->id]);
        $far = $this->makeEvent(['start_date' => '2031-03-04', 'latitude' => $this->metersNorth(30000)]);
        $outsideWindow = $this->makeEvent(['start_date' => '2031-04-20']);
        $cancelled = $this->makeEvent(['start_date' => '2031-03-04', 'status' => 'cancelled']);
        $fridays = $this->makeEvent(['recurrence' => 'weekly', 'weekdays' => [5], 'start_date' => '2031-01-01']);

        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT, $account));

        $response = $this->getJson("/api/admin/v1/accommodations/{$accommodation->id}/nearby-events?from=2031-03-03&to=2031-03-09")
            ->assertOk()
            ->assertJsonPath('data.has_location', true)
            ->assertJsonPath('data.radius_m', 15000);

        $items = collect($response->json('data.items'));
        $ids = $items->pluck('id')->all();

        $this->assertSame([$near->id, $atVenue->id, $fridays->id], $ids);
        $this->assertNotContains($far->id, $ids);
        $this->assertNotContains($outsideWindow->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);

        $this->assertSame('2031-03-07', $items->firstWhere('id', $fridays->id)['occurs_on']);
        $this->assertEqualsWithDelta(3000, $items->firstWhere('id', $atVenue->id)['distance_m'], 30);
    }

    public function test_cercanos_de_otra_cuenta_es_403(): void
    {
        $accommodation = $this->makeAccommodation();
        Sanctum::actingAs($this->makeUser(User::TYPE_ACCOUNT));

        $this->getJson("/api/admin/v1/accommodations/{$accommodation->id}/nearby-events")->assertForbidden();
    }

    public function test_ventana_de_cercanos_acotada_a_90_dias(): void
    {
        $accommodation = $this->makeAccommodation();
        Sanctum::actingAs($this->platform);

        $this->getJson("/api/admin/v1/accommodations/{$accommodation->id}/nearby-events?from=2031-01-01&to=2031-12-31")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }
}
