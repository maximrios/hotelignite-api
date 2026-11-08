<?php

namespace Tests\Feature;

use App\Mail\BookingGuestMail;
use App\Mail\BookingRequestReceivedMail;
use App\Models\Accommodation;
use App\Models\Account;
use App\Models\BookingHold;
use App\Models\City;
use App\Models\Extra;
use App\Models\Plan;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomAvailability;
use App\Models\RoomType;
use App\Models\User;
use App\Support\BookingDeadline;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PlanFeatureSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingEngineTest extends TestCase
{
    use DatabaseTransactions;

    private const KEY = 'engine-test-key';

    private Accommodation $acc;

    private Account $account;

    private RoomType $type;

    private RatePlan $plan;

    private Room $room;

    private string $checkin;

    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();

        config(['api.booking_engine_key' => self::KEY]);
        Mail::fake();

        $this->seed([PlanSeeder::class, FeatureSeeder::class, PlanFeatureSeeder::class]);

        $cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $this->account = Account::create(['name' => 'Cuenta motor']);
        $this->acc = Accommodation::create([
            'account_id' => $this->account->id,
            'city_id' => $cityId,
            'name' => 'Hotel motor',
            'slug' => 'hotel-motor-'.uniqid(),
            'email' => 'hotel@test.com',
            'enabled' => 1,
        ]);
        $this->acc->forceFill([
            'booking_mode' => Accommodation::BOOKING_REQUEST,
            'plan_id' => Plan::where('slug', 'starter')->value('id'),
        ])->save();

        $typeId = DB::table('room_types')->insertGetId([
            'accommodation_id' => $this->acc->id,
            'slug' => 'tipo-'.uniqid(),
            'status' => 'active',
            'max_occupancy' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->type = RoomType::findOrFail($typeId);
        $this->room = $this->createRoom('101');

        $this->plan = RatePlan::create([
            'accommodation_id' => $this->acc->id,
            'is_base' => true,
            'name' => 'Estándar',
            'code' => 'BAR',
            'cancellation' => 'flexible',
            'meal_plan' => 'bb',
            'enabled' => true,
        ]);

        $this->checkin = now()->addDays(10)->toDateString();
        $this->checkout = now()->addDays(12)->toDateString();

        foreach ([10, 11] as $offset) {
            Rate::create([
                'rate_plan_id' => $this->plan->id,
                'room_type_id' => $this->type->id,
                'date' => now()->addDays($offset)->toDateString(),
                'price' => 5000000,
            ]);
        }
    }

    private function createRoom(string $number): Room
    {
        $id = DB::table('rooms')->insertGetId([
            'accommodation_id' => $this->acc->id,
            'room_type_id' => $this->type->id,
            'number' => $number,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Room::findOrFail($id);
    }

    private function url(string $path = ''): string
    {
        return "/api/booking/v1/{$this->acc->slug}{$path}";
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::KEY];
    }

    private function search(): TestResponse
    {
        return $this->getJson($this->url("/search?checkin={$this->checkin}&checkout={$this->checkout}&adults=2"), $this->headers());
    }

    private function hold(): TestResponse
    {
        return $this->postJson($this->url('/holds'), [
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'adults' => 2,
            'room_type_id' => $this->type->id,
            'rate_plan_id' => $this->plan->id,
        ], $this->headers());
    }

    /** @param  array<string, mixed>  $extra */
    private function confirm(string $token, array $extra = []): TestResponse
    {
        return $this->postJson($this->url("/holds/{$token}/confirm"), [
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'email' => 'ana@test.com',
            'phone' => '3875551234',
            'accepted_terms' => true,
        ] + $extra, $this->headers());
    }

    /** @param  array<string, mixed>  $attributes */
    private function extra(array $attributes = []): Extra
    {
        return Extra::create([
            'accommodation_id' => $this->acc->id,
            'name' => 'Extra',
            'price' => 100000,
            'charge_mode' => Extra::MODE_STAY,
            'max_quantity' => 1,
            ...$attributes,
        ]);
    }

    private function hotelUser(): User
    {
        return User::create(['name' => 'dueño '.uniqid(), 'email' => uniqid().'@test.com', 'password' => bcrypt('secret'), 'user_type' => 'account', 'account_id' => $this->account->id]);
    }

    #[Test]
    public function exige_la_credencial_de_servicio(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $this->getJson($this->url(), ['Authorization' => 'Bearer otra'])->assertUnauthorized();
    }

    #[Test]
    public function un_alojamiento_sin_motor_activado_no_vende(): void
    {
        $this->acc->forceFill(['booking_mode' => 'inquiry'])->save();

        $this->getJson($this->url(), $this->headers())->assertNotFound();
    }

    #[Test]
    public function el_plan_free_no_vende(): void
    {
        $this->acc->forceFill(['plan_id' => Plan::where('slug', 'free')->value('id')])->save();

        $this->getJson($this->url(), $this->headers())->assertNotFound();
    }

    #[Test]
    public function busca_con_precio_real(): void
    {
        $this->search()->assertOk()
            ->assertJsonPath('data.nights', 2)
            ->assertJsonPath('data.rooms.0.rooms_left', 1)
            ->assertJsonPath('data.rooms.0.plans.0.total', 100000);
    }

    #[Test]
    public function la_busqueda_respeta_los_topes_del_buscador(): void
    {
        $base = "/search?checkin={$this->checkin}&checkout={$this->checkout}";

        $this->getJson($this->url("{$base}&adults=13"), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('adults');
        $this->getJson($this->url("{$base}&adults=2&children=9"), $this->headers())->assertUnprocessable()->assertJsonValidationErrors('children');
        $this->getJson($this->url("{$base}&adults=12&children=8&rooms=4"), $this->headers())->assertOk();
    }

    #[Test]
    public function la_tarjeta_muestra_las_fotos_del_tipo_en_orden(): void
    {
        $this->acc->images()->create(['url' => 'https://img.test/hotel.jpg', 'order' => 0]);
        $this->type->images()->create(['url' => 'https://img.test/b.jpg', 'order' => 1]);
        $this->type->images()->create(['url' => 'https://img.test/a.jpg', 'order' => 0]);

        $this->search()->assertOk()
            ->assertJsonPath('data.rooms.0.images', ['https://img.test/a.jpg', 'https://img.test/b.jpg']);
    }

    #[Test]
    public function un_tipo_sin_fotos_usa_la_portada_del_hotel(): void
    {
        $this->acc->images()->create(['url' => 'https://img.test/otra.jpg', 'order' => 1]);
        $this->acc->images()->create(['url' => 'https://img.test/portada.jpg', 'order' => 0]);

        $this->search()->assertOk()->assertJsonPath('data.rooms.0.images', ['https://img.test/portada.jpg']);
    }

    #[Test]
    public function sin_fotos_de_nadie_la_lista_viene_vacia(): void
    {
        $this->search()->assertOk()->assertJsonPath('data.rooms.0.images', []);
    }

    #[Test]
    public function no_ofrece_noches_sin_tarifa_ni_cierres(): void
    {
        Rate::where('rate_plan_id', $this->plan->id)->where('date', $this->checkin)->delete();
        $this->search()->assertOk()->assertJsonCount(0, 'data.rooms');

        Rate::create(['rate_plan_id' => $this->plan->id, 'room_type_id' => $this->type->id, 'date' => $this->checkin, 'price' => 5000000, 'stop_sell' => true]);
        $this->search()->assertOk()->assertJsonCount(0, 'data.rooms');

        Rate::where('date', $this->checkin)->where('rate_plan_id', $this->plan->id)->update(['stop_sell' => false]);
        RoomAvailability::create(['room_type_id' => $this->type->id, 'date' => $this->checkin, 'available' => 1, 'total' => 1, 'closed' => true]);
        $this->search()->assertOk()->assertJsonCount(0, 'data.rooms');
    }

    #[Test]
    public function no_ofrece_si_no_entran_los_huespedes(): void
    {
        $this->getJson($this->url("/search?checkin={$this->checkin}&checkout={$this->checkout}&adults=3"), $this->headers())
            ->assertOk()->assertJsonCount(0, 'data.rooms');
    }

    #[Test]
    public function el_hold_aparta_la_ultima_habitacion(): void
    {
        $this->hold()->assertCreated();

        $this->search()->assertOk()->assertJsonCount(0, 'data.rooms');
        $this->hold()->assertStatus(409);
    }

    #[Test]
    public function un_hold_vencido_libera_la_habitacion_y_no_confirma(): void
    {
        $token = $this->hold()->json('data.token');

        BookingHold::query()->update(['expires_at' => now()->subMinute()]);

        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 1);
        $this->getJson($this->url("/holds/{$token}"), $this->headers())->assertJsonPath('data.status', 'expired');
        $this->confirm($token)->assertStatus(410);
    }

    #[Test]
    public function liberar_el_hold_devuelve_la_habitacion_al_momento(): void
    {
        $token = $this->hold()->assertCreated()->json('data.token');
        $this->search()->assertJsonCount(0, 'data.rooms');

        $this->deleteJson($this->url("/holds/{$token}"), [], $this->headers())->assertNoContent();

        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 1);
        $this->confirm($token)->assertStatus(410);
    }

    #[Test]
    public function una_solicitud_enviada_no_se_libera(): void
    {
        $token = $this->hold()->json('data.token');
        $this->confirm($token)->assertOk();

        $this->deleteJson($this->url("/holds/{$token}"), [], $this->headers())->assertStatus(422);
    }

    #[Test]
    public function el_precio_del_hold_no_cambia_si_cambia_la_grilla(): void
    {
        $token = $this->hold()->json('data.token');

        Rate::where('rate_plan_id', $this->plan->id)->update(['price' => 9900000]);

        $this->getJson($this->url("/holds/{$token}"), $this->headers())->assertJsonPath('data.total', 100000);
    }

    #[Test]
    public function el_hold_dice_el_tipo_de_cada_habitacion_para_volver_a_elegirla(): void
    {
        $token = $this->hold()->json('data.token');

        $this->getJson($this->url("/holds/{$token}"), $this->headers())
            ->assertJsonPath('data.rooms.0.room_type_id', $this->type->id)
            ->assertJsonPath('data.rooms.0.max_occupancy', 2)
            ->assertJsonPath('data.rooms.0.plan.id', $this->plan->id);
    }

    #[Test]
    public function el_token_no_sirve_en_otro_alojamiento(): void
    {
        $token = $this->hold()->json('data.token');

        $other = $this->acc->replicate()->fill(['slug' => 'otro-'.uniqid()]);
        $other->forceFill(['booking_mode' => Accommodation::BOOKING_REQUEST, 'plan_id' => $this->acc->plan_id])->save();

        $this->getJson("/api/booking/v1/{$other->slug}/holds/{$token}", $this->headers())->assertNotFound();
    }

    #[Test]
    public function confirmar_crea_una_reserva_pendiente_y_avisa(): void
    {
        $owner = $this->hotelUser();
        $token = $this->hold()->json('data.token');

        $response = $this->confirm($token)->assertOk()->assertJsonPath('data.status', 'submitted');

        $reservation = Reservation::where('confirmation_number', $response->json('data.confirmation_number'))->firstOrFail();

        $this->assertSame(Reservation::STATUS_PENDING, (int) $reservation->status_id);
        $this->assertSame(10000000, (int) $reservation->total_amount);
        // Entra sin habitación: se asigna recién cuando el hotel confirma.
        $this->assertNull($reservation->room_id);
        $this->assertSame(3, (int) $reservation->channel_id);

        Mail::assertQueued(BookingGuestMail::class, fn (BookingGuestMail $m) => $m->kind === BookingGuestMail::RECEIVED && $m->hasTo('ana@test.com'));
        // Un aviso por solicitud, al email del hotel: el usuario lo ve en la campana del PMS.
        Mail::assertQueued(BookingRequestReceivedMail::class, 1);
        Mail::assertQueued(BookingRequestReceivedMail::class, fn (BookingRequestReceivedMail $m) => $m->hasTo('hotel@test.com'));
        Mail::assertNotQueued(BookingRequestReceivedMail::class, fn (BookingRequestReceivedMail $m) => $m->hasTo($owner->email));
    }

    #[Test]
    public function sin_email_del_hotel_avisa_a_los_usuarios_de_la_cuenta(): void
    {
        $owner = $this->hotelUser();
        $this->acc->update(['email' => null]);

        $this->confirm($this->hold()->json('data.token'))->assertOk();

        Mail::assertQueued(BookingRequestReceivedMail::class, fn (BookingRequestReceivedMail $m) => $m->hasTo($owner->email));
    }

    #[Test]
    public function los_mails_del_motor_se_arman_sin_errores(): void
    {
        $parking = $this->extra(['name' => 'Cochera', 'charge_mode' => Extra::MODE_NIGHT, 'price' => 300000]);
        $code = $this->confirm($this->hold()->json('data.token'), [
            'extras' => [['id' => $parking->id, 'quantity' => 1]],
            'special_requests' => 'Piso alto',
        ])->json('data.confirmation_number');
        $reservation = Reservation::where('confirmation_number', $code)->firstOrFail();
        $reservation->update(['attribution' => ['source' => 'instagram']]);

        foreach ([BookingGuestMail::RECEIVED, BookingGuestMail::CONFIRMED, BookingGuestMail::DECLINED, BookingGuestMail::EXPIRED] as $kind) {
            $html = (new BookingGuestMail($reservation, $kind))->render();
            $this->assertStringContainsString($code, $html);
            $this->assertStringContainsString('Cochera', $html);
            $this->assertStringNotContainsString('HotelIgnite</td>', $html);
        }

        $hotel = (new BookingRequestReceivedMail($reservation))->render();
        $this->assertStringContainsString('Confirmar o rechazar en el PMS', $hotel);
        $this->assertStringContainsString('instagram', $hotel);
        $this->assertStringContainsString('/calendario?date=', $hotel);
        $this->assertStringContainsString('Venció', (new BookingRequestReceivedMail($reservation, expired: true))->render());
    }

    #[Test]
    public function repetir_el_envio_no_duplica(): void
    {
        $token = $this->hold()->json('data.token');

        $first = $this->confirm($token)->json('data.confirmation_number');
        $second = $this->confirm($token)->assertOk()->json('data.confirmation_number');

        $this->assertSame($first, $second);
        $this->assertSame(1, Reservation::where('accommodation_id', $this->acc->id)->count());
        Mail::assertQueued(BookingGuestMail::class, 1);
    }

    #[Test]
    public function el_hotel_confirma_la_solicitud(): void
    {
        $user = $this->hotelUser();

        $this->confirm($this->hold()->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/reservations/{$reservation->id}/confirm")->assertOk();
        $this->assertSame(Reservation::STATUS_CONFIRMED, (int) $reservation->refresh()->status_id);
        // Por defecto el hotel le escribe desde su email: el sistema no manda nada.
        Mail::assertNotQueued(BookingGuestMail::class, fn (BookingGuestMail $m) => $m->kind === BookingGuestMail::CONFIRMED);

        $this->postJson("/api/v1/reservations/{$reservation->id}/confirm")->assertStatus(422);
    }

    #[Test]
    public function confirmar_avisa_al_huesped_solo_si_se_pide(): void
    {
        $user = $this->hotelUser();

        $this->confirm($this->hold()->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/reservations/{$reservation->id}/confirm", ['notify_guest' => true])->assertOk();
        Mail::assertQueued(BookingGuestMail::class, fn (BookingGuestMail $m) => $m->kind === BookingGuestMail::CONFIRMED);
    }

    #[Test]
    public function una_solicitud_pendiente_no_bloquea_la_habitacion(): void
    {
        $this->confirm($this->hold()->json('data.token'))->assertOk();

        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 1);
    }

    #[Test]
    public function confirmar_asigna_habitacion_y_recien_ahi_bloquea(): void
    {
        $user = $this->hotelUser();
        $this->confirm($this->hold()->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/reservations/{$reservation->id}/confirm")
            ->assertOk()
            ->assertJsonPath('room', '101');

        $this->assertSame($this->room->id, (int) $reservation->refresh()->room_id);
        $this->search()->assertJsonCount(0, 'data.rooms');
    }

    #[Test]
    public function dos_solicitudes_por_la_ultima_habitacion_la_segunda_no_se_puede_confirmar(): void
    {
        $user = $this->hotelUser();
        $this->confirm($this->hold()->json('data.token'))->assertOk();
        $this->confirm($this->hold()->assertCreated()->json('data.token'))->assertOk();
        [$first, $second] = Reservation::where('accommodation_id', $this->acc->id)->orderBy('created_at')->get()->all();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/reservations/{$first->id}/confirm")->assertOk();

        $this->postJson("/api/v1/reservations/{$second->id}/confirm")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Ya no quedan habitaciones'));
        $this->assertTrue($second->refresh()->isPendingConfirmation());
        $this->assertNull($second->room_id);

        // El hotel la rechaza y no toca el cupo de la confirmada.
        $this->postJson("/api/v1/reservations/{$second->id}/cancel")->assertOk();
        $this->search()->assertJsonCount(0, 'data.rooms');
    }

    #[Test]
    public function la_grilla_de_cupo_se_descuenta_al_confirmar_y_no_antes(): void
    {
        $user = $this->hotelUser();
        $this->createRoom('102');
        $row = RoomAvailability::create(['room_type_id' => $this->type->id, 'date' => $this->checkin, 'available' => 2, 'total' => 2, 'closed' => false]);

        $this->confirm($this->hold()->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();
        $this->assertSame(2, (int) $row->refresh()->available);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/reservations/{$reservation->id}/confirm")->assertOk();
        $this->assertSame(1, (int) $row->refresh()->available);

        $this->postJson("/api/v1/reservations/{$reservation->id}/cancel")->assertOk();
        $this->assertSame(2, (int) $row->refresh()->available);
    }

    #[Test]
    public function rechazar_una_solicitud_no_devuelve_cupo_que_no_tomo(): void
    {
        $user = $this->hotelUser();
        // Cupo 1 de 2: si el rechazo sumara, quedaría en 2.
        $row = RoomAvailability::create(['room_type_id' => $this->type->id, 'date' => $this->checkin, 'available' => 1, 'total' => 2, 'closed' => false]);

        $this->confirm($this->hold()->json('data.token'))->assertOk();
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/reservations/{$reservation->id}/cancel")->assertOk();

        $this->assertSame(1, (int) $row->refresh()->available);
    }

    #[Test]
    public function el_pms_lista_solo_las_solicitudes_sin_responder(): void
    {
        $this->createRoom('102');
        $user = $this->hotelUser();

        $this->confirm($this->hold()->json('data.token'));
        $this->confirm($this->hold()->json('data.token'));
        [$answered, $pending] = Reservation::where('accommodation_id', $this->acc->id)->orderBy('created_at')->get()->all();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/reservations/{$answered->id}/confirm")->assertOk();

        $this->getJson("/api/v1/reservations?pending_request=1&accommodation_id={$this->acc->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.pending_confirmation', true);
    }

    #[Test]
    public function una_solicitud_pendiente_no_admite_recepcion(): void
    {
        $user = $this->hotelUser();

        $this->confirm($this->hold()->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();

        $this->assertSame(['edit', 'confirm_request', 'cancel'], $reservation->availableActions());

        Sanctum::actingAs($user);
        $this->travel(10)->days();

        $this->assertFalse($reservation->refresh()->arrivalPending());
        $this->postJson("/api/v1/reservations/{$reservation->id}/no-show", [])->assertStatus(422);
        $this->postJson("/api/v1/reservations/{$reservation->id}/check-in", [])->assertStatus(422);
    }

    #[Test]
    public function rechazar_libera_la_habitacion_sin_avisar_por_defecto(): void
    {
        $user = $this->hotelUser();

        $this->confirm($this->hold()->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->firstOrFail();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/reservations/{$reservation->id}/cancel", ['reason' => 'Sin lugar'])->assertOk();

        Mail::assertNotQueued(BookingGuestMail::class, fn (BookingGuestMail $m) => $m->kind === BookingGuestMail::DECLINED);
        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 1);
    }

    private function holdRooms(array $items, int $adults, int $children = 0, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->postJson($this->url('/holds'), [
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'adults' => $adults,
            'children' => $children,
            'items' => array_map(fn (RatePlan $plan) => ['room_type_id' => $this->type->id, 'rate_plan_id' => $plan->id], $items),
        ], $this->headers() + ['X-Engine-Client-Ip' => $ip]);
    }

    #[Test]
    public function la_solicitud_guarda_documento_y_arma_los_pedidos_para_el_hotel(): void
    {
        $token = $this->holdRooms([$this->plan], 1, 1)->json('data.token');

        $code = $this->confirm($token, [
            'document_type' => 'dni',
            'document_number' => '30111222',
            'nationality' => 'ar',
            'arrival_time' => '18:00 – 20:00',
            'children_ages' => [6],
            'payment_plan' => 'deposit',
            'payment_method' => 'mercadopago',
            'newsletter' => true,
            'special_requests' => 'Piso alto',
        ])->assertOk()->json('data.confirmation_number');

        $reservation = Reservation::where('confirmation_number', $code)->firstOrFail();
        $this->assertSame('30111222', $reservation->guest->document_number);
        $this->assertSame('AR', $reservation->guest->nationality);
        $this->assertSame(
            "Llegada estimada: 18:00 – 20:00\nEdades de los menores: 6 años\nPrefiere pagar seña por Mercado Pago\nQuiere recibir promociones del hotel por email\nPiso alto",
            $reservation->special_requests,
        );
    }

    #[Test]
    public function el_origen_de_la_visita_pasa_del_hold_a_la_reserva(): void
    {
        $token = $this->postJson($this->url('/holds'), [
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'adults' => 2,
            'room_type_id' => $this->type->id,
            'rate_plan_id' => $this->plan->id,
            'attribution' => ['source' => ' google ', 'medium' => 'cpc', 'campaign' => ''],
        ], $this->headers())->assertCreated()->json('data.token');

        $code = $this->confirm($token)->assertOk()
            ->assertJsonPath('data.attribution', ['source' => 'google', 'medium' => 'cpc'])
            ->json('data.confirmation_number');

        $this->assertSame(['source' => 'google', 'medium' => 'cpc'], Reservation::where('confirmation_number', $code)->first()->attribution);
    }

    #[Test]
    public function el_origen_solo_acepta_utm_conocidos(): void
    {
        $this->postJson($this->url('/holds'), [
            'checkin' => $this->checkin,
            'checkout' => $this->checkout,
            'adults' => 2,
            'room_type_id' => $this->type->id,
            'rate_plan_id' => $this->plan->id,
            'attribution' => ['source' => 'google', 'gclid' => 'x'],
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('attribution');
    }

    #[Test]
    public function el_hold_ofrece_los_extras_activos_con_el_precio_de_la_estadia(): void
    {
        $this->extra(['name' => 'Cochera', 'charge_mode' => Extra::MODE_NIGHT, 'price' => 300000, 'max_quantity' => 2, 'image_url' => 'https://img.test/cochera.jpg']);
        $this->extra(['name' => 'Spa', 'charge_mode' => Extra::MODE_PERSON_NIGHT, 'price' => 100000]);
        $this->extra(['name' => 'Pausado', 'enabled' => false]);
        $this->extra(['name' => 'Borrado'])->delete();

        $token = $this->hold()->json('data.token');

        $this->getJson($this->url("/holds/{$token}"), $this->headers())->assertOk()
            ->assertJsonCount(2, 'data.extras')
            ->assertJsonPath('data.extras.0.name', 'Cochera')
            ->assertJsonPath('data.extras.0.units', 2)
            ->assertJsonPath('data.extras.0.total', 6000)
            ->assertJsonPath('data.extras.0.max_quantity', 2)
            ->assertJsonPath('data.extras.0.image_url', 'https://img.test/cochera.jpg')
            ->assertJsonPath('data.extras.1.image_url', null)
            // 2 adultos × 2 noches × $1.000
            ->assertJsonPath('data.extras.1.total', 4000);
    }

    #[Test]
    public function el_desayuno_sale_incluido_si_la_tarifa_lo_trae_y_no_se_cobra(): void
    {
        // El plan del setUp es bb: ya trae desayuno.
        $breakfast = $this->extra(['template' => Extra::TEMPLATE_BREAKFAST, 'name' => 'Desayuno', 'charge_mode' => Extra::MODE_PERSON_NIGHT]);
        $token = $this->hold()->json('data.token');

        $this->getJson($this->url("/holds/{$token}"), $this->headers())->assertJsonPath('data.extras.0.included', true);
        $this->confirm($token, ['extras' => [['id' => $breakfast->id, 'quantity' => 1]]])->assertUnprocessable();
    }

    #[Test]
    public function confirmar_guarda_los_extras_con_el_precio_del_momento(): void
    {
        $parking = $this->extra(['name' => 'Cochera', 'charge_mode' => Extra::MODE_NIGHT, 'price' => 300000, 'max_quantity' => 2]);
        $token = $this->hold()->json('data.token');

        $code = $this->confirm($token, ['extras' => [['id' => $parking->id, 'quantity' => 2]]])->assertOk()
            ->assertJsonPath('data.chosen_extras.0.name', 'Cochera')
            ->assertJsonPath('data.extras_total', 12000)
            ->json('data.confirmation_number');

        $parking->update(['price' => 999900, 'name' => 'Cochera techada']);

        $line = Reservation::where('confirmation_number', $code)->firstOrFail()->extras()->sole();
        $this->assertSame('Cochera', $line->name);
        $this->assertSame(300000, $line->unit_price);
        $this->assertSame(2, $line->units);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(1200000, $line->total_amount);
    }

    #[Test]
    public function no_se_aceptan_extras_ajenos_pausados_ni_de_mas(): void
    {
        $parking = $this->extra(['name' => 'Cochera', 'max_quantity' => 1]);
        $paused = $this->extra(['name' => 'Pausado', 'enabled' => false]);
        $other = Extra::create(['accommodation_id' => Accommodation::create(['account_id' => $this->account->id, 'name' => 'Otro', 'slug' => 'otro-'.uniqid(), 'enabled' => 1])->id, 'name' => 'Ajeno', 'price' => 100, 'charge_mode' => Extra::MODE_STAY]);
        $token = $this->hold()->json('data.token');

        $this->confirm($token, ['extras' => [['id' => $parking->id, 'quantity' => 2]]])->assertUnprocessable();
        $this->confirm($token, ['extras' => [['id' => $paused->id, 'quantity' => 1]]])->assertUnprocessable();
        $this->confirm($token, ['extras' => [['id' => $other->id, 'quantity' => 1]]])->assertUnprocessable();
        $this->confirm($token, ['extras' => ['parking']])->assertUnprocessable()->assertJsonValidationErrors('extras.0.id');
        $this->assertFalse(Reservation::where('accommodation_id', $this->acc->id)->exists());
    }

    #[Test]
    public function el_huesped_reparte_a_los_pasajeros_entre_las_habitaciones(): void
    {
        $this->createRoom('102');
        // El hold reparte 1 + 1 adultos y el menor en la primera.
        $token = $this->holdRooms([$this->plan, $this->plan], 2, 1)->json('data.token');

        $this->confirm($token, ['occupancy' => [['adults' => 1, 'children' => 0], ['adults' => 1, 'children' => 1]]])->assertOk();

        $this->getJson($this->url("/holds/{$token}"), $this->headers())
            ->assertJsonPath('data.rooms.0.children', 0)
            ->assertJsonPath('data.rooms.1.children', 1);
    }

    #[Test]
    public function un_reparto_que_no_suma_o_no_entra_se_rechaza(): void
    {
        $this->createRoom('102');
        $token = $this->holdRooms([$this->plan, $this->plan], 2, 1)->json('data.token');
        $before = Reservation::count();

        $this->confirm($token, ['occupancy' => [['adults' => 1, 'children' => 0], ['adults' => 1, 'children' => 0]]])->assertStatus(422);
        $this->confirm($token, ['occupancy' => [['adults' => 1, 'children' => 0], ['adults' => 2, 'children' => 1]]])->assertStatus(422);
        $this->confirm($token, ['occupancy' => [['adults' => 2, 'children' => 1], ['adults' => 0, 'children' => 0]]])->assertStatus(422);
        $this->assertSame($before, Reservation::count());
    }

    #[Test]
    public function aparta_varias_habitaciones_y_crea_un_grupo(): void
    {
        $this->createRoom('102');

        $token = $this->holdRooms([$this->plan, $this->plan], 3, 1)->assertCreated()->json('data.token');

        $this->getJson($this->url("/holds/{$token}"), $this->headers())
            ->assertJsonCount(2, 'data.rooms')
            ->assertJsonPath('data.total', 200000);

        $this->confirm($token)->assertOk()->assertJsonPath('data.status', 'submitted');

        $group = Reservation::where('accommodation_id', $this->acc->id)->get();
        $this->assertCount(2, $group);
        $this->assertSame(1, $group->pluck('booking_group_id')->unique()->count());
        $this->assertSame(3, (int) $group->sum('adults'));
        $this->assertSame(1, (int) $group->sum('children'));
        $this->assertNotNull($group->first()->request_expires_at);
        Mail::assertQueued(BookingRequestReceivedMail::class, 1);
    }

    #[Test]
    public function si_falta_una_habitacion_no_aparta_ninguna(): void
    {
        $this->holdRooms([$this->plan, $this->plan], 2)->assertStatus(409);

        $this->assertSame(0, BookingHold::where('accommodation_id', $this->acc->id)->count());
        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 1);
    }

    #[Test]
    public function rechaza_una_seleccion_donde_no_entran_los_huespedes(): void
    {
        $this->createRoom('102');

        $this->holdRooms([$this->plan, $this->plan], 5)->assertStatus(422);
        $this->holdRooms([$this->plan, $this->plan], 1, 1)->assertStatus(422);
    }

    #[Test]
    public function no_acepta_mas_del_maximo_de_habitaciones(): void
    {
        $this->holdRooms(array_fill(0, 5, $this->plan), 5)->assertStatus(422);
    }

    #[Test]
    public function limita_las_habitaciones_apartadas_por_visitante(): void
    {
        foreach (range(102, 112) as $number) {
            $this->createRoom((string) $number);
        }

        $this->holdRooms(array_fill(0, 4, $this->plan), 4)->assertCreated();
        $this->holdRooms(array_fill(0, 3, $this->plan), 3)->assertStatus(429);
    }

    #[Test]
    public function limita_la_parte_del_hotel_apartada_a_la_vez(): void
    {
        foreach (range(102, 108) as $number) {
            $this->createRoom((string) $number);
        }

        $this->holdRooms(array_fill(0, 4, $this->plan), 4, 0, '10.0.0.2')->assertCreated();
        $this->holdRooms([$this->plan], 1, 0, '10.0.0.3')->assertStatus(429);
    }

    #[Test]
    public function limita_las_solicitudes_pendientes_por_email(): void
    {
        $this->createRoom('102');
        $this->createRoom('103');

        $this->confirm($this->holdRooms([$this->plan], 1)->json('data.token'))->assertOk();
        $this->confirm($this->holdRooms([$this->plan], 1)->json('data.token'))->assertOk();
        $this->confirm($this->holdRooms([$this->plan], 1)->json('data.token'))->assertStatus(429);
    }

    #[Test]
    public function exige_turnstile_cuando_esta_configurado(): void
    {
        config(['services.turnstile.secret_key' => 'secreto']);

        $this->holdRooms([$this->plan], 1)->assertStatus(422)->assertJsonValidationErrors('cf-turnstile-response');
    }

    #[Test]
    public function el_hotel_confirma_el_grupo_entero(): void
    {
        $user = $this->hotelUser();
        $this->createRoom('102');
        $this->confirm($this->holdRooms([$this->plan, $this->plan], 2)->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->first();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/reservations/{$reservation->id}/confirm")->assertOk();

        $group = Reservation::where('accommodation_id', $this->acc->id)->get();
        $this->assertTrue($group->every(fn (Reservation $r) => (int) $r->status_id === Reservation::STATUS_CONFIRMED));
        $this->assertTrue($group->every(fn (Reservation $r) => $r->request_expires_at === null));
    }

    #[Test]
    public function el_hotel_rechaza_el_grupo_entero(): void
    {
        $user = $this->hotelUser();
        $this->createRoom('102');
        $this->confirm($this->holdRooms([$this->plan, $this->plan], 2)->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->first();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/reservations/{$reservation->id}/cancel", ['reason' => 'Sin lugar', 'notify_guest' => true])->assertOk();

        $group = Reservation::where('accommodation_id', $this->acc->id)->get();
        $this->assertTrue($group->every(fn (Reservation $r) => $r->cancelled_at !== null));
        // Con la casilla marcada sale un solo aviso por el grupo entero.
        $this->assertCount(1, Mail::queued(BookingGuestMail::class, fn (BookingGuestMail $m) => $m->kind === BookingGuestMail::DECLINED));
    }

    #[Test]
    public function una_solicitud_vencida_cancela_el_grupo_y_libera_todo(): void
    {
        $this->createRoom('102');
        $this->confirm($this->holdRooms([$this->plan, $this->plan], 2)->json('data.token'));
        // Mientras espera respuesta no bloquea nada.
        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 2);

        Reservation::where('accommodation_id', $this->acc->id)->update(['request_expires_at' => now()->subMinute()]);
        $this->artisan('booking:expire-requests')->assertSuccessful();

        $group = Reservation::where('accommodation_id', $this->acc->id)->get();
        $this->assertTrue($group->every(fn (Reservation $r) => $r->cancelled_at !== null));
        $this->search()->assertJsonPath('data.rooms.0.rooms_left', 2);
        Mail::assertQueued(BookingGuestMail::class, fn (BookingGuestMail $mail) => $mail->kind === BookingGuestMail::EXPIRED);
        Mail::assertQueued(BookingRequestReceivedMail::class, fn (BookingRequestReceivedMail $mail) => $mail->expired);
    }

    #[Test]
    public function no_vence_lo_que_todavia_tiene_tiempo(): void
    {
        $this->confirm($this->holdRooms([$this->plan], 1)->json('data.token'));

        $this->artisan('booking:expire-requests')->assertSuccessful();

        $this->assertNull(Reservation::where('accommodation_id', $this->acc->id)->first()->cancelled_at);
    }

    #[Test]
    public function los_mails_del_motor_se_renderizan(): void
    {
        $this->createRoom('102');
        $this->confirm($this->holdRooms([$this->plan, $this->plan], 2)->json('data.token'));
        $reservation = Reservation::where('accommodation_id', $this->acc->id)->first();

        foreach ([BookingGuestMail::RECEIVED, BookingGuestMail::CONFIRMED, BookingGuestMail::DECLINED, BookingGuestMail::EXPIRED] as $kind) {
            $this->assertStringContainsString($reservation->confirmation_number, (new BookingGuestMail($reservation, $kind))->render());
        }

        $this->assertStringContainsString('se cancela sola', (new BookingRequestReceivedMail($reservation))->render());
        $this->assertStringContainsString('Venció', (new BookingRequestReceivedMail($reservation, expired: true))->render());
    }

    #[Test]
    public function el_reloj_corre_solo_en_horario_de_atencion(): void
    {
        $this->acc->forceFill(['booking_request_minutes' => 60, 'booking_attention_from' => '08:00:00', 'booking_attention_to' => '23:00:00']);
        $tz = config('app.destination_timezone');

        $madrugada = BookingDeadline::for($this->acc, now()->addDays(10), Carbon::parse('2030-03-05 03:00', $tz));
        $this->assertSame('2030-03-05 09:00', $madrugada->setTimezone($tz)->format('Y-m-d H:i'));

        $tarde = BookingDeadline::for($this->acc, now()->addDays(10), Carbon::parse('2030-03-05 22:30', $tz));
        $this->assertSame('2030-03-06 08:30', $tarde->setTimezone($tz)->format('Y-m-d H:i'));
    }

    #[Test]
    public function el_plazo_respeta_los_limites_y_el_dia_de_llegada(): void
    {
        $tz = config('app.destination_timezone');
        $now = Carbon::parse('2030-03-05 10:00', $tz);

        $this->acc->forceFill(['booking_request_minutes' => 5, 'booking_attention_from' => null, 'booking_attention_to' => null]);
        $this->assertSame('10:30', BookingDeadline::for($this->acc, $now->copy()->addDays(5), $now)->setTimezone($tz)->format('H:i'));

        $this->acc->forceFill(['booking_request_minutes' => 720, 'booking_attention_from' => '08:00:00', 'booking_attention_to' => '12:00:00']);
        $this->assertSame('2030-03-05 23:59', BookingDeadline::for($this->acc, $now, $now)->setTimezone($tz)->format('Y-m-d H:i'));
    }
}
