<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Account;
use App\Models\City;
use App\Models\HousekeepingEvent;
use App\Models\HousekeepingStaff;
use App\Models\HousekeepingTask;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Housekeeping del PMS: tablero, tareas automáticas, transiciones, personal, historial. */
class HousekeepingTest extends TestCase
{
    use DatabaseTransactions;

    private Accommodation $acc;

    private Account $account;

    private User $user;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cityId = City::query()->value('id')
            ?? DB::table('cities')->insertGetId(['name' => 'Ciudad test', 'slug' => 'ciudad-test-'.uniqid()]);

        $this->account = Account::create(['name' => 'Cuenta housekeeping test']);
        $this->acc = $this->hotel($this->account);
        $this->user = $this->userFor($this->account, 'Ana');
        Sanctum::actingAs($this->user);
    }

    private function hotel(Account $account): Accommodation
    {
        return Accommodation::create([
            'account_id' => $account->id,
            'city_id' => $this->cityId,
            'name' => 'Hotel housekeeping test',
            'slug' => 'hotel-hk-test-'.uniqid(),
            // Housekeeping es del Básico en adelante.
            'plan_id' => Plan::where('slug', 'starter')->value('id'),
        ]);
    }

    private function userFor(Account $account, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'user_type' => User::TYPE_ACCOUNT,
            'account_id' => $account->id,
        ]);
    }

    private function room(string $number, string $status = 'vc', ?int $floor = 1, ?Accommodation $acc = null): Room
    {
        return Room::create([
            'accommodation_id' => ($acc ?? $this->acc)->id,
            'room_type_id' => 0,
            'number' => $number,
            'floor' => $floor,
            'housekeeping_status' => $status,
        ]);
    }

    /** $from/$to son días relativos a hoy (hora del hotel); $in/$out marcan check-in / check-out hechos. */
    private function reservation(Room $room, int $from, int $to, bool $in = false, bool $out = false, ?string $hour = null): string
    {
        $id = (string) Str::uuid();
        $checkin = Reservation::today()->addDays($from)->toDateString();
        $checkout = Reservation::today()->addDays($to)->toDateString();
        DB::table('reservations')->insert([
            'id' => $id, 'accommodation_id' => $room->accommodation_id,
            'guest_id' => DB::table('guests')->value('id') ?? DB::table('guests')->insertGetId(['first_name' => 'T', 'last_name' => 'G']),
            'room_id' => $room->id, 'room_type_id' => 0, 'currency_id' => 'ARS', 'language_id' => 'es',
            'package_id' => 0, 'status_id' => 2, 'payment_method_id' => 0, 'arrival' => $checkin, 'departure' => $checkout,
            'checkin_date' => $checkin, 'checkout_date' => $checkout, 'reservation_type' => 1, 'created' => now(), 'modified' => now(),
            'checked_in_at' => $in || $out ? now()->subDays(max(1, -$from)) : null,
            'checked_out_at' => $out ? now() : null,
            'arrival_hour' => $hour ?? '0',
        ]);

        return $id;
    }

    private function board(?Accommodation $acc = null): array
    {
        return $this->getJson('/api/v1/housekeeping?accommodation_id='.($acc ?? $this->acc)->id)->assertOk()->json();
    }

    /** @return array<string, mixed> */
    private function card(array $board, Room $room): array
    {
        return collect($board['rooms'])->firstWhere('id', $room->id);
    }

    private function taskOf(array $card, string $type): ?array
    {
        return collect($card['tasks'])->firstWhere('type', $type);
    }

    // ─── Generación perezosa ──────────────────────────────────────────────────

    #[Test]
    public function genera_las_tareas_del_dia_con_su_prioridad(): void
    {
        $dirty = $this->room('1', 'vd');
        $inHouse = $this->room('2', 'oc');
        $this->reservation($inHouse, -1, 2, in: true);
        $rotation = $this->room('3', 'oc');
        $this->reservation($rotation, -2, 0, in: true);
        $this->reservation($rotation, 0, 2, hour: '15:30');
        $arrivalOd = $this->room('4', 'od');
        $this->reservation($arrivalOd, 0, 1, hour: '9:05');
        $arrivalClean = $this->room('5', 'vc');
        $this->reservation($arrivalClean, 0, 1);
        $dirtyArrival = $this->room('6', 'vd');
        $this->reservation($dirtyArrival, 0, 3);
        $ooo = $this->room('7', 'ooo');
        $arrivedToday = $this->room('8', 'oc');
        $this->reservation($arrivedToday, 0, 2, in: true);
        $odStay = $this->room('9', 'od');
        $this->reservation($odStay, 0, 2, in: true);

        $board = $this->board();

        $this->assertSame(3, $this->taskOf($this->card($board, $dirty), 'checkout')['priority']);
        $this->assertSame(4, $this->taskOf($this->card($board, $inHouse), 'daily')['priority']);
        $this->assertSame(1, $this->taskOf($this->card($board, $rotation), 'checkout')['priority']);
        $this->assertTrue($this->card($board, $rotation)['arrives_today']);
        $this->assertSame('15:30', $this->card($board, $rotation)['arrival_time']);
        $this->assertSame(2, $this->taskOf($this->card($board, $arrivalOd), 'arrival')['priority']);
        $this->assertSame('09:05', $this->card($board, $arrivalOd)['arrival_time']);
        $this->assertSame([], $this->card($board, $arrivalClean)['tasks']);
        $this->assertSame(2, $this->taskOf($this->card($board, $dirtyArrival), 'checkout')['priority']);
        $this->assertNull($this->taskOf($this->card($board, $dirtyArrival), 'arrival'));
        $this->assertSame([], $this->card($board, $ooo)['tasks']);
        $this->assertSame([], $this->card($board, $arrivedToday)['tasks']);
        $this->assertSame(4, $this->taskOf($this->card($board, $odStay), 'daily')['priority']);
    }

    #[Test]
    public function la_generacion_es_idempotente_y_no_duplica_ni_el_historial(): void
    {
        $this->room('1', 'vd');
        $inHouse = $this->room('2', 'oc');
        $this->reservation($inHouse, -1, 2, in: true);

        $this->board();
        $tasks = HousekeepingTask::where('accommodation_id', $this->acc->id)->count();
        $events = HousekeepingEvent::where('accommodation_id', $this->acc->id)->count();
        $this->board();
        $this->board();

        $this->assertSame(2, $tasks);
        $this->assertSame($tasks, HousekeepingTask::where('accommodation_id', $this->acc->id)->count());
        $this->assertSame($events, HousekeepingEvent::where('accommodation_id', $this->acc->id)->count());
    }

    #[Test]
    public function reconcilia_cuando_cambia_la_situacion_sin_borrar_historial(): void
    {
        $a = $this->room('1', 'oc');
        $b = $this->room('2', 'vc');
        $reservation = $this->reservation($a, -1, 3, in: true);

        $first = $this->taskOf($this->card($this->board(), $a), 'daily');
        $this->assertSame('pending', $first['status']);

        // Cambio de habitación: la reserva se muda a B.
        DB::table('reservations')->where('id', $reservation)->update(['room_id' => $b->id]);
        $a->update(['housekeeping_status' => 'vc']);
        $b->update(['housekeeping_status' => 'oc']);

        $board = $this->board();
        $this->assertNull($this->taskOf($this->card($board, $a), 'daily'));
        $this->assertNotNull($this->taskOf($this->card($board, $b), 'daily'));
        $this->assertSame('cancelled', HousekeepingTask::find($first['id'])->status);
        $this->assertTrue(HousekeepingEvent::where('task_id', $first['id'])->where('type', 'task_cancelled')->exists());

        // Salida anticipada: check-out hoy. La repaso se cancela y aparece la limpieza de salida.
        $daily = $this->taskOf($this->card($board, $b), 'daily');
        DB::table('reservations')->where('id', $reservation)->update(['checked_out_at' => now(), 'checkout_date' => Reservation::today()->toDateString()]);
        $b->update(['housekeeping_status' => 'vd']);

        $board = $this->board();
        $this->assertSame('cancelled', HousekeepingTask::find($daily['id'])->status);
        $this->assertSame(3, $this->taskOf($this->card($board, $b), 'checkout')['priority']);

        // Deshacer el check-out: vuelve el repaso (se reactiva la fila cancelada, no se duplica).
        DB::table('reservations')->where('id', $reservation)->update(['checked_out_at' => null, 'checkout_date' => Reservation::today()->addDays(3)->toDateString()]);
        $b->update(['housekeeping_status' => 'oc']);

        $board = $this->board();
        $this->assertSame('pending', $this->taskOf($this->card($board, $b), 'daily')['status']);
        $this->assertSame($daily['id'], $this->taskOf($this->card($board, $b), 'daily')['id']);
        $this->assertNull($this->taskOf($this->card($board, $b), 'checkout'));
        $this->assertSame(1, HousekeepingTask::where('room_id', $b->id)->where('type', 'daily')->count());
    }

    #[Test]
    public function las_terminadas_y_en_curso_no_se_cancelan_y_las_vencidas_si(): void
    {
        $room = $this->room('1', 'vd');
        $board = $this->board();
        $task = $this->taskOf($this->card($board, $room), 'checkout');
        $this->postJson("/api/v1/housekeeping/tasks/{$task['id']}/start")->assertOk();

        // Ya no es vd, pero la tarea está en curso: no se toca.
        $room->update(['housekeeping_status' => 'vc']);
        $this->board();
        $this->assertSame('in_progress', HousekeepingTask::find($task['id'])->status);

        $stale = HousekeepingTask::create([
            'accommodation_id' => $this->acc->id, 'room_id' => $room->id, 'type' => 'daily', 'priority' => 4,
            'status' => 'pending', 'date' => Reservation::today()->subDay()->toDateString(),
        ]);
        $this->board();
        $this->assertSame('cancelled', $stale->refresh()->status);
    }

    #[Test]
    public function una_limpia_antes_del_checkout_se_reabre_cuando_queda_sucia(): void
    {
        $room = $this->room('1', 'oc');
        $this->reservation($room, -1, 0, in: true);
        $task = $this->taskOf($this->card($this->board(), $room), 'checkout');

        $this->postJson("/api/v1/housekeeping/tasks/{$task['id']}/finish")->assertOk();
        $this->assertSame('done', HousekeepingTask::find($task['id'])->status);

        // Recepción registra después la salida: la habitación vuelve a vd.
        DB::table('reservations')->where('room_id', $room->id)->update(['checked_out_at' => now()]);
        $room->refresh()->update(['housekeeping_status' => 'vd']);

        $card = $this->card($this->board(), $room);
        $this->assertSame('pending', $this->taskOf($card, 'checkout')['status']);
        $this->assertSame('dirty', $card['state']);
    }

    #[Test]
    public function no_molestar_omite_el_repaso_y_queda_en_el_historial(): void
    {
        $room = $this->room('1', 'oc');
        $this->reservation($room, -1, 3, in: true);
        $this->assertNotNull($this->taskOf($this->card($this->board(), $room), 'daily'));

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/dnd", ['enabled' => true, 'note' => 'Servicio rechazado'])
            ->assertOk()->assertJsonPath('room.dnd', true);

        $board = $this->board();
        $this->assertNull($this->taskOf($this->card($board, $room), 'daily'));
        $this->assertTrue(HousekeepingEvent::where('room_id', $room->id)->where('type', 'dnd_set')->where('note', 'Servicio rechazado')->exists());

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/dnd", ['enabled' => false])->assertOk();
        $this->assertNotNull($this->taskOf($this->card($this->board(), $room), 'daily'));
    }

    #[Test]
    public function no_molestar_exige_una_habitacion_ocupada(): void
    {
        $room = $this->room('1', 'vc');

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/dnd", ['enabled' => true])->assertUnprocessable();
    }

    // ─── Transiciones ─────────────────────────────────────────────────────────

    #[Test]
    public function iniciar_y_terminar_la_limpieza_cambia_el_estado_al_final(): void
    {
        $room = $this->room('1', 'vd');
        $staff = HousekeepingStaff::create(['accommodation_id' => $this->acc->id, 'name' => 'Marta']);
        $task = $this->taskOf($this->card($this->board(), $room), 'checkout');

        $this->postJson("/api/v1/housekeeping/tasks/{$task['id']}/start", ['staff_id' => $staff->id])
            ->assertOk()->assertJsonPath('room.state', 'cleaning')->assertJsonPath('room.housekeeping_status', 'vd');
        $this->postJson("/api/v1/housekeeping/tasks/{$task['id']}/start")->assertStatus(409);

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/clean")
            ->assertOk()->assertJsonPath('room.state', 'ready')->assertJsonPath('room.housekeeping_status', 'vc')
            ->assertJsonPath('room.tasks.0.status', 'done')->assertJsonPath('room.tasks.0.staff_name', 'Marta');

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/clean")->assertUnprocessable();
    }

    #[Test]
    public function limpiar_un_repaso_de_una_ocupada_sucia_la_deja_ocupada_limpia(): void
    {
        $room = $this->room('1', 'od');
        $this->reservation($room, -1, 3, in: true);
        $this->board();

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/clean")->assertOk()->assertJsonPath('room.housekeeping_status', 'oc');
    }

    #[Test]
    public function el_setting_de_inspeccion_decide_si_limpia_es_lista_o_por_inspeccionar(): void
    {
        $room = $this->room('1', 'vd');
        $this->board();

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/clean")->assertJsonPath('room.state', 'ready');
        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/inspect")->assertOk()->assertJsonPath('room.state', 'ready');
        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/dirty")->assertOk()->assertJsonPath('room.housekeeping_status', 'vd');
        $this->assertNull($room->refresh()->inspected_at);

        $this->putJson('/api/v1/housekeeping/settings', ['accommodation_id' => $this->acc->id, 'requires_inspection' => true])
            ->assertOk()->assertJsonPath('requires_inspection', true);

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/clean")
            ->assertOk()->assertJsonPath('room.state', 'to_inspect');
        $this->assertSame(1, $this->board()['counts']['to_inspect']);

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/inspect")->assertOk()->assertJsonPath('room.state', 'ready');
        $this->assertNotNull($room->refresh()->inspected_at);
        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/inspect")->assertUnprocessable();
    }

    #[Test]
    public function inspeccionar_exige_vacia_y_limpia(): void
    {
        $room = $this->room('1', 'vd');

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/inspect")->assertUnprocessable();
    }

    #[Test]
    public function sucia_revierte_la_limpieza_y_reabre_la_tarea(): void
    {
        $room = $this->room('1', 'vd');
        $task = $this->taskOf($this->card($this->board(), $room), 'checkout');
        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/clean")->assertOk();
        $this->assertSame('done', HousekeepingTask::find($task['id'])->status);

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/dirty")
            ->assertOk()->assertJsonPath('room.state', 'dirty')->assertJsonPath('room.tasks.0.status', 'pending');

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/dirty")->assertUnprocessable();
    }

    #[Test]
    public function reportar_un_problema_pone_fuera_de_servicio_y_cancela_las_tareas(): void
    {
        $room = $this->room('1', 'vd');
        $this->board();

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/problem", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/problem", [
            'reason' => 'Pérdida en el baño', 'estimated_return' => Reservation::today()->addDays(2)->toDateString(),
        ])->assertOk()->assertJsonPath('room.state', 'out_of_service')->assertJsonPath('room.tasks', []);

        $room->refresh();
        $this->assertSame('ooo', $room->housekeeping_status);
        $this->assertSame('Pérdida en el baño', $room->maintenance_note);
        $this->assertSame(Reservation::today()->addDays(2)->toDateString(), $room->out_of_service_until->toDateString());
        $this->assertSame(1, $this->board()['counts']['out_of_service']);

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/problem", ['reason' => 'otra'])->assertStatus(409);
        $this->postJson('/api/v1/housekeeping/tasks', ['accommodation_id' => $this->acc->id, 'room_id' => $room->id, 'note' => 'x'])->assertCreated();

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/resolve")
            ->assertOk()->assertJsonPath('room.housekeeping_status', 'vd');
        $room->refresh();
        $this->assertNull($room->maintenance_note);
        $this->assertNull($room->out_of_service_until);

        $types = HousekeepingEvent::where('room_id', $room->id)->pluck('type')->all();
        $this->assertContains('problem_reported', $types);
        $this->assertSame(
            'Pérdida en el baño',
            HousekeepingEvent::where('room_id', $room->id)->where('type', 'problem_reported')->value('note')
        );
    }

    #[Test]
    public function no_se_reporta_un_problema_con_un_huesped_alojado(): void
    {
        $room = $this->room('1', 'oc');
        $this->reservation($room, -1, 2, in: true);

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/problem", ['reason' => 'Aire'])->assertStatus(409);
        $this->assertSame('oc', $room->refresh()->housekeeping_status);
    }

    #[Test]
    public function una_fuera_de_servicio_no_se_inicia_ni_se_limpia(): void
    {
        $room = $this->room('1', 'ooo');

        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/clean")->assertStatus(409);
    }

    // ─── Tareas manuales, personal, asignación ────────────────────────────────

    #[Test]
    public function las_tareas_manuales_no_tocan_el_estado_de_la_habitacion(): void
    {
        $room = $this->room('1', 'vc');

        $id = $this->postJson('/api/v1/housekeeping/tasks', ['accommodation_id' => $this->acc->id, 'room_id' => $room->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['note']);

        $id = $this->postJson('/api/v1/housekeeping/tasks', ['accommodation_id' => $this->acc->id, 'room_id' => $room->id, 'note' => 'Cambiar cortinas'])
            ->assertCreated()->assertJsonPath('room.tasks.0.type', 'manual')->json('room.tasks.0.id');

        $this->postJson("/api/v1/housekeeping/tasks/{$id}/finish")->assertOk()->assertJsonPath('room.tasks.0.status', 'done');
        $this->assertSame('vc', $room->refresh()->housekeeping_status);

        $this->board();
        $this->assertSame(1, HousekeepingTask::where('room_id', $room->id)->where('type', 'manual')->count());
    }

    #[Test]
    public function una_manual_pendiente_de_ayer_sigue_en_el_tablero(): void
    {
        $room = $this->room('1', 'vc');
        $task = HousekeepingTask::create([
            'accommodation_id' => $this->acc->id, 'room_id' => $room->id, 'type' => 'manual', 'priority' => 3,
            'status' => 'pending', 'date' => Reservation::today()->subDay()->toDateString(), 'note' => 'Pintar',
        ]);

        $this->assertSame($task->id, $this->card($this->board(), $room)['tasks'][0]['id']);
    }

    #[Test]
    public function abm_de_personal_con_nombre_unico_y_baja_logica_si_tiene_historial(): void
    {
        $this->postJson('/api/v1/housekeeping/staff', ['accommodation_id' => $this->acc->id])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $marta = $this->postJson('/api/v1/housekeeping/staff', ['accommodation_id' => $this->acc->id, 'name' => 'Marta'])
            ->assertCreated()->assertJsonPath('active', true)->json('id');
        $this->postJson('/api/v1/housekeeping/staff', ['accommodation_id' => $this->acc->id, 'name' => 'marta'])->assertUnprocessable();

        $this->patchJson("/api/v1/housekeeping/staff/{$marta}", ['name' => 'Marta G.'])->assertOk()->assertJsonPath('name', 'Marta G.');
        $this->assertCount(1, $this->board()['staff']);

        $room = $this->room('1', 'vd');
        $task = $this->taskOf($this->card($this->board(), $room), 'checkout');
        $this->patchJson("/api/v1/housekeeping/tasks/{$task['id']}", ['staff_id' => $marta])->assertOk()->assertJsonPath('room.tasks.0.staff_name', 'Marta G.');

        $this->deleteJson("/api/v1/housekeeping/staff/{$marta}")->assertOk()->assertJsonPath('deactivated', true);
        $this->assertFalse(HousekeepingStaff::find($marta)->active);
        $this->patchJson("/api/v1/housekeeping/tasks/{$task['id']}", ['staff_id' => $marta])->assertUnprocessable();

        $lola = $this->postJson('/api/v1/housekeeping/staff', ['accommodation_id' => $this->acc->id, 'name' => 'Lola'])->json('id');
        $this->deleteJson("/api/v1/housekeeping/staff/{$lola}")->assertOk()->assertJsonPath('deleted', true);
        $this->assertNull(HousekeepingStaff::find($lola));
    }

    #[Test]
    public function asignacion_en_lote_por_piso(): void
    {
        $marta = HousekeepingStaff::create(['accommodation_id' => $this->acc->id, 'name' => 'Marta'])->id;
        $r1 = $this->room('201', 'vd', 2);
        $r2 = $this->room('202', 'vd', 2);
        $r3 = $this->room('101', 'vd', 1);
        $this->board();

        $this->postJson('/api/v1/housekeeping/tasks/bulk-assign', ['accommodation_id' => $this->acc->id, 'staff_id' => $marta, 'floor' => 2])
            ->assertOk()->assertJsonPath('assigned', 2);

        $board = $this->board();
        $this->assertSame($marta, $this->card($board, $r1)['tasks'][0]['staff_id']);
        $this->assertSame($marta, $this->card($board, $r2)['tasks'][0]['staff_id']);
        $this->assertNull($this->card($board, $r3)['tasks'][0]['staff_id']);

        $this->postJson('/api/v1/housekeeping/tasks/bulk-assign', ['accommodation_id' => $this->acc->id, 'staff_id' => $marta, 'floor' => 2])
            ->assertJsonPath('assigned', 0);
        $this->postJson('/api/v1/housekeeping/tasks/bulk-assign', ['accommodation_id' => $this->acc->id, 'staff_id' => $marta, 'room_ids' => [$r3->id]])
            ->assertJsonPath('assigned', 1);
        $this->postJson('/api/v1/housekeeping/tasks/bulk-assign', ['accommodation_id' => $this->acc->id, 'staff_id' => $marta])
            ->assertUnprocessable();
    }

    // ─── Historial ────────────────────────────────────────────────────────────

    #[Test]
    public function el_observer_registra_los_cambios_de_recepcion_como_sistema(): void
    {
        $room = $this->room('1', 'vc');
        $room->update(['housekeeping_status' => 'oc']);
        $room->update(['housekeeping_status' => 'vd']);

        $events = HousekeepingEvent::where('room_id', $room->id)->orderBy('id')->get();

        $this->assertCount(2, $events);
        $this->assertSame(['vc', 'oc'], [$events[0]->from_status, $events[0]->to_status]);
        $this->assertSame(['oc', 'vd'], [$events[1]->from_status, $events[1]->to_status]);
        $this->assertSame('system', $events[0]->source);
        $this->assertSame($this->user->id, $events[0]->user_id);
    }

    #[Test]
    public function el_observer_limpia_la_inspeccion_al_ensuciar_y_registra_el_motivo(): void
    {
        $room = $this->room('1', 'vc');
        $room->update(['inspected_at' => now()]);
        $this->assertNotNull($room->refresh()->inspected_at);

        $room->update(['housekeeping_status' => 'ooo', 'maintenance_note' => 'Pintura', 'out_of_service_until' => '2030-01-01']);
        $this->assertNotNull($room->refresh()->inspected_at);
        $this->assertSame('Pintura', HousekeepingEvent::where('room_id', $room->id)->latest('id')->value('note'));

        $room->update(['housekeeping_status' => 'vd', 'maintenance_note' => null]);
        $this->assertNull($room->refresh()->inspected_at);
        $this->assertNull($room->out_of_service_until);

        $room->update(['maintenance_note' => 'nota suelta']);
        $this->assertSame('note_changed', HousekeepingEvent::where('room_id', $room->id)->latest('id')->value('type'));
    }

    #[Test]
    public function las_acciones_del_modulo_no_duplican_el_evento_del_observer(): void
    {
        $room = $this->room('1', 'vd');
        $this->board();
        $this->postJson("/api/v1/housekeeping/rooms/{$room->id}/clean")->assertOk();

        $this->assertSame(1, HousekeepingEvent::where('room_id', $room->id)->where('type', 'status_changed')->count());
        $event = HousekeepingEvent::where('room_id', $room->id)->where('type', 'status_changed')->first();
        $this->assertSame('user', $event->source);
        $this->assertSame($this->user->id, $event->user_id);
        $this->assertNotNull($event->task_id);
    }

    #[Test]
    public function el_historial_se_pagina_de_a_30_filtra_por_tipo_y_va_en_orden_inverso(): void
    {
        $room = $this->room('1', 'vc');
        $staff = HousekeepingStaff::create(['accommodation_id' => $this->acc->id, 'name' => 'Marta']);
        for ($i = 0; $i < 35; $i++) {
            HousekeepingEvent::create([
                'accommodation_id' => $this->acc->id, 'room_id' => $room->id, 'type' => $i % 5 === 0 ? 'inspected' : 'status_changed',
                'from_status' => 'vd', 'to_status' => 'vc', 'user_id' => $this->user->id, 'staff_id' => $staff->id,
                'note' => "n{$i}", 'created_at' => now()->subMinutes(100 - $i),
            ]);
        }

        $page1 = $this->getJson("/api/v1/housekeeping/rooms/{$room->id}/events")->assertOk();
        $this->assertCount(30, $page1->json('data'));
        $this->assertSame(35, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.last_page'));
        $this->assertSame('n34', $page1->json('data.0.note'));
        $this->assertSame('Ana', $page1->json('data.0.user'));
        $this->assertSame('Marta', $page1->json('data.0.staff'));

        $this->getJson("/api/v1/housekeeping/rooms/{$room->id}/events?page=2")->assertOk()->assertJsonCount(5, 'data');
        $this->getJson("/api/v1/housekeeping/rooms/{$room->id}/events?type[]=inspected")->assertOk()->assertJsonPath('meta.total', 7);
    }

    // ─── Tenancy ──────────────────────────────────────────────────────────────

    #[Test]
    public function no_se_ve_ni_se_toca_otro_alojamiento(): void
    {
        $otherAccount = Account::create(['name' => 'Otra cuenta']);
        $otherAcc = $this->hotel($otherAccount);
        $foreign = $this->room('1', 'vd', 1, $otherAcc);
        $foreignStaff = HousekeepingStaff::create(['accommodation_id' => $otherAcc->id, 'name' => 'Ajena']);
        Sanctum::actingAs($this->userFor($otherAccount, 'Dueña'));
        $this->board($otherAcc);
        $foreignTask = HousekeepingTask::where('room_id', $foreign->id)->first();
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/housekeeping?accommodation_id='.$otherAcc->id)->assertNotFound();
        $this->getJson("/api/v1/housekeeping/rooms/{$foreign->id}/events")->assertNotFound();
        $this->postJson("/api/v1/housekeeping/rooms/{$foreign->id}/clean")->assertNotFound();
        $this->postJson("/api/v1/housekeeping/rooms/{$foreign->id}/problem", ['reason' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/housekeeping/tasks/{$foreignTask->id}/start")->assertNotFound();
        $this->postJson('/api/v1/housekeeping/staff', ['accommodation_id' => $otherAcc->id, 'name' => 'Xavi'])->assertNotFound();
        $this->deleteJson("/api/v1/housekeeping/staff/{$foreignStaff->id}")->assertNotFound();
        $this->putJson('/api/v1/housekeeping/settings', ['accommodation_id' => $otherAcc->id, 'requires_inspection' => true])->assertNotFound();
        $this->postJson('/api/v1/housekeeping/tasks', ['accommodation_id' => $otherAcc->id, 'room_id' => $foreign->id, 'note' => 'x'])->assertNotFound();

        // Una habitación o persona ajena tampoco entra por el propio hotel.
        $mine = $this->room('9', 'vd');
        $this->postJson('/api/v1/housekeeping/tasks', ['accommodation_id' => $this->acc->id, 'room_id' => $foreign->id, 'note' => 'x'])->assertUnprocessable();
        $task = $this->taskOf($this->card($this->board(), $mine), 'checkout');
        $this->patchJson("/api/v1/housekeeping/tasks/{$task['id']}", ['staff_id' => $foreignStaff->id])->assertUnprocessable();

        $this->assertSame('vd', $foreign->refresh()->housekeeping_status);
    }

    #[Test]
    public function el_recurso_de_habitaciones_expone_la_inspeccion_para_el_rack(): void
    {
        $room = $this->room('1', 'vc');
        $room->update(['inspected_at' => now()]);
        $this->acc->update(['housekeeping_requires_inspection' => true]);

        $this->getJson('/api/v1/rooms?accommodation_id='.$this->acc->id)
            ->assertOk()
            ->assertJsonPath('data.0.requires_inspection', true)
            ->assertJsonPath('data.0.housekeeping_status', 'vc')
            ->assertJsonPath('data.0.inspected_at', fn ($v) => is_string($v));
    }
}
