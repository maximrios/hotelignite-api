<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Accommodation;
use App\Models\HousekeepingEvent;
use App\Models\HousekeepingStaff;
use App\Models\HousekeepingTask;
use App\Models\Reservation;
use App\Models\Room;
use App\Repositories\Concerns\ScopesToAccommodation;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Housekeeping del PMS. Reglas acordadas con hotelería:
 *
 * - "En limpieza" no es un estado de la habitación: es una tarea en curso. El
 *   estado (vd→vc, od→oc) cambia al terminar la tarea.
 * - Las tareas automáticas se generan de forma perezosa al abrir la pantalla y se
 *   reconcilian en cada apertura (idempotente): ver `reconcile()`.
 * - Una vacía-limpia sin `inspected_at` es "por inspeccionar" sólo si el hotel
 *   exige inspección; si no, limpia = lista.
 * - El personal no tiene login: el historial guarda al usuario que registró y a
 *   la persona asignada por separado.
 */
class HousekeepingRepository
{
    use ScopesToAccommodation {
        visibleAccommodation as private visibleAccommodationOf;
        writableAccommodation as private writableAccommodationOf;
    }

    // Housekeeping es del plan Básico en adelante: todo acceso al módulo pasa
    // por estos dos (tablero, tareas, habitación, personal, historial).
    protected function visibleAccommodation($accommodationId): Accommodation
    {
        $accommodation = $this->visibleAccommodationOf($accommodationId);
        $accommodation->requireFeature('housekeeping', 'Housekeeping');

        return $accommodation;
    }

    protected function writableAccommodation($accommodationId): Accommodation
    {
        $accommodation = $this->writableAccommodationOf($accommodationId);
        $accommodation->requireFeature('housekeeping', 'Housekeeping');

        return $accommodation;
    }

    /** Prioridades: 1 rotación (llega y sale alguien hoy) · 2 llega hoy · 3 salida · 4 repaso. */
    public const PRIORITY_ROTATION = 1;

    public const PRIORITY_ARRIVAL = 2;

    public const PRIORITY_CHECKOUT = 3;

    public const PRIORITY_DAILY = 4;

    public const EVENTS_PER_PAGE = 30;

    // ─── Tablero ──────────────────────────────────────────────────────────────

    public function board(int $accommodationId): array
    {
        $accommodation = $this->visibleAccommodation($accommodationId);
        $today = Reservation::today();
        $rooms = Room::active()->where('accommodation_id', $accommodation->id)->get();

        // Un usuario de sólo lectura (portal B2B) mira el tablero sin generar nada.
        if (Gate::allows('update', $accommodation)) {
            $this->reconcile($accommodation, $rooms, $this->situations($accommodation->id, $today), $today);
        }

        $cards = $this->cards($accommodation, $rooms, $today);

        return [
            'date' => $today->toDateString(),
            'requires_inspection' => (bool) $accommodation->housekeeping_requires_inspection,
            'counts' => $this->counts($cards),
            'staff' => HousekeepingStaff::where('accommodation_id', $accommodation->id)
                ->orderBy('name')->get()->map(fn (HousekeepingStaff $s) => $this->staffPayload($s))->values(),
            'rooms' => $cards->values(),
        ];
    }

    /**
     * Genera y reconcilia las tareas automáticas del día. Para cada habitación activa
     * (las fuera de servicio no tienen tareas) se calcula qué tareas corresponden:
     *
     * - limpieza de salida (`checkout`): sale un huésped hoy (o tiene salida vencida
     *   sin check-out) o la habitación está vacía-sucia. Prioridad 1 si además llega
     *   alguien hoy y sale alguien hoy (rotación), 2 si llega hoy, 3 si no.
     * - preparación de llegada (`arrival`): llega alguien hoy, no hay limpieza de
     *   salida que ya la cubra y la habitación no está vacía-limpia. Prioridad 2.
     * - repaso diario (`daily`): huésped alojado (no el día que llega, salvo que esté
     *   sucia), sin "no molestar" ni limpieza de salida. Prioridad 4.
     *
     * Sobre lo ya existente: se crean las que faltan, se reactivan las canceladas que
     * vuelven a aplicar, se actualiza la prioridad de las abiertas y se cancelan las
     * abiertas (sin iniciar) que ya no aplican. Las terminadas y las en curso no se
     * tocan; nada se borra. Las abiertas de días anteriores se cancelan como vencidas.
     * Las manuales nunca se reconcilian.
     *
     * @param  Collection<int, Room>  $rooms
     * @param  array<int, array<string, mixed>>  $situations
     */
    public function reconcile(Accommodation $accommodation, Collection $rooms, array $situations, Carbon $today): void
    {
        $date = $today->toDateString();

        $existing = HousekeepingTask::where('accommodation_id', $accommodation->id)
            ->where('type', '<>', HousekeepingTask::TYPE_MANUAL)
            ->where(fn ($q) => $q->where('date', $date)->orWhereIn('status', [HousekeepingTask::PENDING, HousekeepingTask::IN_PROGRESS]))
            ->get();

        foreach ($existing->filter(fn (HousekeepingTask $t) => $t->date->toDateString() < $date && $t->isActive()) as $stale) {
            $this->cancelTask($stale, 'Vencida: no se hizo el día que correspondía.');
        }

        $todays = $existing->filter(fn (HousekeepingTask $t) => $t->date->toDateString() === $date)
            ->groupBy(fn (HousekeepingTask $t) => $t->room_id.'|'.$t->type);

        foreach ($rooms as $room) {
            $desired = $this->desiredTasks($room, $situations[$room->id] ?? $this->emptySituation(), $today);

            foreach ([HousekeepingTask::TYPE_CHECKOUT, HousekeepingTask::TYPE_ARRIVAL, HousekeepingTask::TYPE_DAILY] as $type) {
                /** @var HousekeepingTask|null $task */
                $task = $todays->get($room->id.'|'.$type)?->first();
                $priority = $desired[$type] ?? null;

                if ($priority !== null) {
                    if ($task === null) {
                        $this->createAuto($accommodation, $room, $type, $priority, $date);
                    } elseif ($task->status === HousekeepingTask::CANCELLED) {
                        $task->update(['status' => HousekeepingTask::PENDING, 'priority' => $priority, 'cancelled_at' => null, 'started_at' => null]);
                        $this->event($room, 'task_created', ['task_id' => $task->id, 'source' => 'system', 'note' => 'Volvió a aplicar.']);
                    } elseif ($task->isActive() && $task->priority !== $priority) {
                        $task->update(['priority' => $priority]);
                    } elseif ($type === HousekeepingTask::TYPE_CHECKOUT && $task->status === HousekeepingTask::DONE
                        && $room->housekeeping_status === 'vd') {
                        // Se limpió antes de que recepción registrara la salida y volvió a quedar sucia.
                        $task->update(['status' => HousekeepingTask::PENDING, 'priority' => $priority, 'started_at' => null, 'finished_at' => null]);
                        $this->event($room, 'task_created', ['task_id' => $task->id, 'source' => 'system', 'note' => 'Reabierta: la habitación quedó sucia.']);
                    }
                } elseif ($task !== null && $task->status === HousekeepingTask::PENDING) {
                    $this->cancelTask($task, 'Ya no aplica: cambió la situación de la habitación.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $situation
     * @return array<string, int> tipo => prioridad
     */
    public function desiredTasks(Room $room, array $situation, Carbon $today): array
    {
        if (in_array($room->housekeeping_status, ['ooo', 'oos'], true)) {
            return [];
        }

        $desired = [];
        $needsCheckout = $situation['departs'] || $room->housekeeping_status === 'vd';

        if ($needsCheckout) {
            $desired[HousekeepingTask::TYPE_CHECKOUT] = $situation['arrives']
                ? ($situation['departs'] ? self::PRIORITY_ROTATION : self::PRIORITY_ARRIVAL)
                : self::PRIORITY_CHECKOUT;
        } elseif ($situation['arrives'] && $room->housekeeping_status !== 'vc') {
            $desired[HousekeepingTask::TYPE_ARRIVAL] = self::PRIORITY_ARRIVAL;
        } elseif ($situation['in_house'] && ! $this->isDnd($room, $today)
            && ($situation['stayed_before'] || $room->housekeeping_status === 'od')) {
            $desired[HousekeepingTask::TYPE_DAILY] = self::PRIORITY_DAILY;
        }

        return $desired;
    }

    // ─── Acciones sobre tareas ────────────────────────────────────────────────

    public function storeManualTask(array $data): array
    {
        $accommodation = $this->writableAccommodation($data['accommodation_id']);
        $room = $this->activeRoom($accommodation, (int) $data['room_id']);
        $staff = $this->staffOrNull($accommodation, $data['staff_id'] ?? null);

        $task = HousekeepingTask::create([
            'accommodation_id' => $accommodation->id,
            'room_id' => $room->id,
            'date' => Reservation::today()->toDateString(),
            'type' => HousekeepingTask::TYPE_MANUAL,
            'priority' => self::PRIORITY_CHECKOUT,
            'status' => HousekeepingTask::PENDING,
            'staff_id' => $staff?->id,
            'note' => $data['note'],
        ]);
        $this->event($room, 'task_created', ['task_id' => $task->id, 'staff_id' => $staff?->id, 'note' => $task->note]);

        return $this->roomResult($accommodation, $room);
    }

    /** Asigna (o desasigna con `staff_id` null) una tarea abierta. */
    public function assignTask(int $id, array $data): array
    {
        [$task, $accommodation, $room] = $this->taskContext($id);
        abort_unless($task->isActive(), 409, 'La tarea ya está cerrada.');
        $staff = $this->staffOrNull($accommodation, $data['staff_id'] ?? null);

        if ($task->staff_id !== $staff?->id) {
            $task->update(['staff_id' => $staff?->id]);
            $this->event($room, 'task_assigned', ['task_id' => $task->id, 'staff_id' => $staff?->id, 'note' => $staff === null ? 'Sin asignar.' : null]);
        }

        return $this->roomResult($accommodation, $room);
    }

    /**
     * Asignación en lote ("todo el piso 2 a Marta"): las tareas abiertas de hoy de
     * las habitaciones del piso (o de la lista). Devuelve cuántas se asignaron.
     */
    public function bulkAssign(array $data): array
    {
        $accommodation = $this->writableAccommodation($data['accommodation_id']);
        $staff = $this->staffOrNull($accommodation, $data['staff_id']);
        $today = Reservation::today();

        $rooms = Room::active()->where('accommodation_id', $accommodation->id)
            ->when(isset($data['room_ids']), fn ($q) => $q->whereIn('id', $data['room_ids']))
            ->when(! isset($data['room_ids']) && array_key_exists('floor', $data), fn ($q) => $data['floor'] === null
                ? $q->whereNull('floor') : $q->where('floor', $data['floor']))
            ->get()->keyBy('id');

        $tasks = $this->openTasks($accommodation->id, $today)->whereIn('room_id', $rooms->keys())->get();
        $count = 0;

        DB::transaction(function () use ($tasks, $staff, $rooms, &$count) {
            foreach ($tasks as $task) {
                if ($task->staff_id === $staff->id) {
                    continue;
                }
                $task->update(['staff_id' => $staff->id]);
                $this->event($rooms[$task->room_id], 'task_assigned', ['task_id' => $task->id, 'staff_id' => $staff->id]);
                $count++;
            }
        });

        return ['assigned' => $count];
    }

    public function startTask(int $id, array $data): array
    {
        [$task, $accommodation, $room] = $this->taskContext($id);
        $this->assertInService($room);
        abort_if($task->status === HousekeepingTask::IN_PROGRESS, 409, 'La tarea ya está en curso.');
        abort_unless($task->status === HousekeepingTask::PENDING, 409, 'La tarea ya está cerrada.');
        $staff = $this->staffOrNull($accommodation, $data['staff_id'] ?? null);

        $task->update([
            'status' => HousekeepingTask::IN_PROGRESS,
            'started_at' => now(),
            'staff_id' => $staff?->id ?? $task->staff_id,
        ]);
        $this->event($room, 'task_started', ['task_id' => $task->id, 'staff_id' => $task->staff_id]);

        return $this->roomResult($accommodation, $room);
    }

    /** Termina la tarea; si es de limpieza, también deja limpia la habitación. */
    public function finishTask(int $id, array $data): array
    {
        [$task, $accommodation, $room] = $this->taskContext($id);
        abort_unless($task->isActive(), 409, 'La tarea ya está cerrada.');
        $staff = $this->staffOrNull($accommodation, $data['staff_id'] ?? null);

        DB::transaction(function () use ($task, $room, $staff) {
            if ($task->isManual()) {
                $this->closeTask($task, $room, $staff?->id);
            } else {
                $this->assertInService($room);
                $this->markClean($room, $staff?->id, $task);
            }
        });

        return $this->roomResult($accommodation, $room);
    }

    public function cancelOpenTask(int $id): array
    {
        [$task, $accommodation, $room] = $this->taskContext($id);
        abort_unless($task->isActive(), 409, 'La tarea ya está cerrada.');
        $this->cancelTask($task, 'Cancelada a mano.');

        return $this->roomResult($accommodation, $room);
    }

    // ─── Acciones sobre habitaciones ──────────────────────────────────────────

    /** "Limpia": vd→vc / od→oc y cierra las tareas de limpieza abiertas. */
    public function clean(int $roomId, array $data): array
    {
        [$room, $accommodation] = $this->roomContext($roomId);
        $this->assertInService($room);
        $staff = $this->staffOrNull($accommodation, $data['staff_id'] ?? null);
        $today = Reservation::today();

        $hasOpenAuto = $this->openTasks($accommodation->id, $today)->where('room_id', $room->id)
            ->where('type', '<>', HousekeepingTask::TYPE_MANUAL)->exists();
        abort_unless(
            $hasOpenAuto || in_array($room->housekeeping_status, ['vd', 'od'], true),
            422,
            'La habitación ya está limpia.'
        );

        DB::transaction(fn () => $this->markClean($room, $staff?->id, null));

        return $this->roomResult($accommodation, $room);
    }

    /** "Inspeccionada": sólo una vacía-limpia. */
    public function inspect(int $roomId, array $data): array
    {
        [$room, $accommodation] = $this->roomContext($roomId);
        abort_unless($room->housekeeping_status === 'vc', 422, 'Sólo se inspecciona una habitación vacía y limpia.');
        abort_if($room->inspected_at !== null, 422, 'La habitación ya está inspeccionada.');
        $staff = $this->staffOrNull($accommodation, $data['staff_id'] ?? null);

        HousekeepingEvent::silently(function () use ($room) {
            $room->inspected_at = now();
            $room->save();
        });
        $this->event($room, 'inspected', ['staff_id' => $staff?->id]);

        return $this->roomResult($accommodation, $room);
    }

    /** "Sucia": revierte una limpieza (vc→vd, oc→od) y reabre la tarea que se había cerrado. */
    public function dirty(int $roomId, array $data): array
    {
        [$room, $accommodation] = $this->roomContext($roomId);
        $to = ['vc' => 'vd', 'oc' => 'od'][$room->housekeeping_status] ?? null;
        abort_if($to === null, 422, 'Sólo una habitación limpia puede pasar a sucia.');
        $staff = $this->staffOrNull($accommodation, $data['staff_id'] ?? null);

        DB::transaction(function () use ($room, $to, $staff) {
            $from = $room->housekeeping_status;
            $this->saveStatus($room, $to);

            $reopened = HousekeepingTask::where('room_id', $room->id)
                ->where('date', Reservation::today()->toDateString())
                ->where('type', '<>', HousekeepingTask::TYPE_MANUAL)
                ->where('status', HousekeepingTask::DONE)
                ->orderByDesc('finished_at')->first();
            $reopened?->update(['status' => HousekeepingTask::PENDING, 'started_at' => null, 'finished_at' => null]);

            $this->event($room, 'status_changed', [
                'task_id' => $reopened?->id, 'staff_id' => $staff?->id ?? $reopened?->staff_id,
                'from_status' => $from, 'to_status' => $to, 'note' => 'Revertida a sucia.',
            ]);
        });

        return $this->roomResult($accommodation, $room);
    }

    /** "Reportar problema": fuera de servicio con motivo obligatorio. */
    public function problem(int $roomId, array $data): array
    {
        [$room, $accommodation] = $this->roomContext($roomId);
        abort_if($room->housekeeping_status === 'ooo', 409, 'La habitación ya está fuera de servicio.');
        abort_if($room->housekeeping_status === 'oos', 409, 'Está fuera de inventario: se gestiona desde Habitaciones.');
        abort_if(
            $this->hasGuestInHouse($room),
            409,
            'Tiene un huésped alojado: hacé el check-out o mudalo antes de ponerla fuera de servicio.'
        );
        $staff = $this->staffOrNull($accommodation, $data['staff_id'] ?? null);

        DB::transaction(function () use ($room, $data, $staff) {
            $from = $room->housekeeping_status;
            HousekeepingEvent::silently(function () use ($room, $data) {
                $room->housekeeping_status = 'ooo';
                $room->maintenance_note = $data['reason'];
                $room->out_of_service_until = $data['estimated_return'] ?? null;
                $room->save();
            });
            $this->event($room, 'problem_reported', [
                'staff_id' => $staff?->id, 'from_status' => $from, 'to_status' => 'ooo', 'note' => $data['reason'],
            ]);

            foreach ($this->openTasks($room->accommodation_id, Reservation::today())->where('room_id', $room->id)->get() as $task) {
                $this->cancelTask($task, 'La habitación quedó fuera de servicio.');
            }
        });

        return $this->roomResult($accommodation, $room);
    }

    /** Vuelve a servicio una fuera de servicio (pasa a sucia: hay que revisarla). */
    public function resolve(int $roomId): array
    {
        [$room, $accommodation] = $this->roomContext($roomId);
        abort_if($room->housekeeping_status === 'oos', 409, 'Está fuera de inventario: se gestiona desde Habitaciones.');
        abort_unless($room->housekeeping_status === 'ooo', 422, 'La habitación no está fuera de servicio.');

        DB::transaction(function () use ($room) {
            HousekeepingEvent::silently(function () use ($room) {
                $room->housekeeping_status = 'vd';
                $room->maintenance_note = null;
                $room->save();
            });
            $this->event($room, 'status_changed', ['from_status' => 'ooo', 'to_status' => 'vd', 'note' => 'Vuelve a servicio.']);
        });

        return $this->roomResult($accommodation, $room);
    }

    /** "No molestar" del día (sobre una habitación ocupada). "Servicio rechazado" = con nota. */
    public function dnd(int $roomId, array $data): array
    {
        [$room, $accommodation] = $this->roomContext($roomId);
        $enabled = (bool) $data['enabled'];
        $today = Reservation::today();

        if ($enabled) {
            abort_unless(in_array($room->housekeeping_status, ['oc', 'od'], true), 422, '"No molestar" es para habitaciones ocupadas.');
        }
        $staff = $this->staffOrNull($accommodation, $data['staff_id'] ?? null);

        DB::transaction(function () use ($room, $data, $enabled, $today, $staff, $accommodation) {
            $room->dnd_date = $enabled ? $today->toDateString() : null;
            HousekeepingEvent::silently(fn () => $room->save());
            $this->event($room, $enabled ? 'dnd_set' : 'dnd_cleared', ['staff_id' => $staff?->id, 'note' => $data['note'] ?? null]);

            if ($enabled) {
                $daily = $this->openTasks($accommodation->id, $today)->where('room_id', $room->id)
                    ->where('type', HousekeepingTask::TYPE_DAILY)->get();
                foreach ($daily as $task) {
                    $this->cancelTask($task, 'No molestar.');
                }
            }
        });

        return $this->roomResult($accommodation, $room);
    }

    // ─── Historial ────────────────────────────────────────────────────────────

    public function events(int $roomId, array $filters): array
    {
        $room = Room::find($roomId);
        abort_if($room === null, 404);
        $this->visibleAccommodation($room->accommodation_id);

        $page = HousekeepingEvent::where('room_id', $room->id)
            ->when(! empty($filters['type']), fn ($q) => $q->whereIn('type', (array) $filters['type']))
            ->with(['user:id,name', 'staff:id,name', 'task:id,type'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::EVENTS_PER_PAGE, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'data' => $page->getCollection()->map(fn (HousekeepingEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'user' => $e->user?->name,
                'staff' => $e->staff?->name,
                'source' => $e->source,
                'note' => $e->note,
                'task_type' => $e->task?->type,
                'created_at' => $e->created_at->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    // ─── Personal y ajustes ───────────────────────────────────────────────────

    public function storeStaff(array $data): array
    {
        $accommodation = $this->writableAccommodation($data['accommodation_id']);
        $name = trim($data['name']);
        $this->assertUniqueStaffName($accommodation->id, $name);

        return $this->staffPayload(HousekeepingStaff::create([
            'accommodation_id' => $accommodation->id,
            'name' => $name,
            'active' => true,
        ]));
    }

    public function updateStaff(int $id, array $data): array
    {
        $staff = HousekeepingStaff::find($id);
        abort_if($staff === null, 404);
        $this->writableAccommodation($staff->accommodation_id);

        if (isset($data['name'])) {
            $name = trim($data['name']);
            $this->assertUniqueStaffName($staff->accommodation_id, $name, $staff->id);
            $staff->name = $name;
        }
        if (isset($data['active'])) {
            $staff->active = (bool) $data['active'];
        }
        $staff->save();

        return $this->staffPayload($staff);
    }

    /** Sin historial se borra; con historial se desactiva (las tareas y eventos conservan el nombre). */
    public function destroyStaff(int $id): array
    {
        $staff = HousekeepingStaff::find($id);
        abort_if($staff === null, 404);
        $this->writableAccommodation($staff->accommodation_id);

        $used = HousekeepingTask::where('staff_id', $staff->id)->exists()
            || HousekeepingEvent::where('staff_id', $staff->id)->exists();

        if ($used) {
            $staff->update(['active' => false]);

            return ['deleted' => false, 'deactivated' => true];
        }

        $staff->delete();

        return ['deleted' => true, 'deactivated' => false];
    }

    public function updateSettings(array $data): array
    {
        $accommodation = $this->writableAccommodation($data['accommodation_id']);
        $accommodation->housekeeping_requires_inspection = (bool) $data['requires_inspection'];
        $accommodation->save();

        return ['requires_inspection' => (bool) $accommodation->housekeeping_requires_inspection];
    }

    // ─── Armado de la respuesta ───────────────────────────────────────────────

    /**
     * Una tarjeta por habitación activa: estado de limpieza derivado + tareas del día.
     *
     * @param  Collection<int, Room>  $rooms
     * @return Collection<int, array<string, mixed>>
     */
    private function cards(Accommodation $accommodation, Collection $rooms, Carbon $today): Collection
    {
        $situations = $this->situations($accommodation->id, $today, $rooms->pluck('id')->all());
        $tasks = $this->shownTasks($accommodation->id, $today)->whereIn('room_id', $rooms->pluck('id'))
            ->with('staff:id,name')->orderBy('priority')->orderBy('id')->get()->groupBy('room_id');
        $requires = (bool) $accommodation->housekeeping_requires_inspection;

        return $rooms
            ->sortBy(fn (Room $r) => sprintf('%05d|%s', $r->floor ?? 99999, str_pad((string) $r->number, 12, '0', STR_PAD_LEFT)), SORT_NATURAL)
            ->map(function (Room $room) use ($situations, $tasks, $requires, $today) {
                $s = $situations[$room->id] ?? $this->emptySituation();
                $roomTasks = $tasks->get($room->id, collect());

                return [
                    'id' => $room->id,
                    'number' => $room->number,
                    'floor' => $room->floor,
                    'housekeeping_status' => $room->housekeeping_status,
                    'state' => $this->cleaningState($room, $roomTasks, $requires),
                    'inspected_at' => $room->inspected_at?->toIso8601String(),
                    'maintenance_note' => $room->maintenance_note,
                    'out_of_service_until' => $room->out_of_service_until?->toDateString(),
                    'dnd' => $this->isDnd($room, $today),
                    'arrives_today' => $s['arrives'],
                    'arrival_time' => $s['arrival_time'],
                    'departs_today' => $s['departs'],
                    'in_house' => $s['in_house'] || $s['departs'],
                    'tasks' => $roomTasks->map(fn (HousekeepingTask $t) => $this->taskPayload($t))->values(),
                ];
            })->values();
    }

    /**
     * Estado de limpieza para mostrar (no es el de la habitación): dirty · cleaning ·
     * to_inspect · ready · out_of_service.
     */
    public function cleaningState(Room $room, Collection $tasks, bool $requiresInspection): string
    {
        if (in_array($room->housekeeping_status, ['ooo', 'oos'], true)) {
            return 'out_of_service';
        }
        if ($tasks->contains(fn (HousekeepingTask $t) => $t->status === HousekeepingTask::IN_PROGRESS)) {
            return 'cleaning';
        }
        // Sucia, o limpia pero con una tarea de limpieza (repaso) todavía por hacer.
        if (in_array($room->housekeeping_status, ['vd', 'od'], true)
            || $tasks->contains(fn (HousekeepingTask $t) => $t->isActive() && ! $t->isManual())) {
            return 'dirty';
        }
        if ($requiresInspection && $room->housekeeping_status === 'vc' && $room->inspected_at === null) {
            return 'to_inspect';
        }

        return 'ready';
    }

    /** @param Collection<int, array<string, mixed>> $cards */
    private function counts(Collection $cards): array
    {
        $by = $cards->countBy('state');

        return [
            'dirty' => $by->get('dirty', 0),
            'cleaning' => $by->get('cleaning', 0),
            'to_inspect' => $by->get('to_inspect', 0),
            'ready' => $by->get('ready', 0),
            'out_of_service' => $by->get('out_of_service', 0),
        ];
    }

    private function taskPayload(HousekeepingTask $t): array
    {
        return [
            'id' => $t->id,
            'type' => $t->type,
            'priority' => $t->priority,
            'status' => $t->status,
            'date' => $t->date->toDateString(),
            'staff_id' => $t->staff_id,
            'staff_name' => $t->staff?->name,
            'note' => $t->note,
            'started_at' => $t->started_at?->toIso8601String(),
            'finished_at' => $t->finished_at?->toIso8601String(),
        ];
    }

    private function staffPayload(HousekeepingStaff $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'active' => (bool) $s->active];
    }

    /** Tarjeta de una sola habitación, para que el PMS la reemplace sin recargar todo. */
    private function roomResult(Accommodation $accommodation, Room $room): array
    {
        $card = $this->cards($accommodation, collect([$room->refresh()]), Reservation::today())->first();

        return ['room' => $card];
    }

    // ─── Situación de las reservas ────────────────────────────────────────────

    /**
     * Qué pasa hoy en cada habitación según sus reservas: `departs` (sale hoy o tiene
     * la salida vencida sin check-out), `arrives` (debe llegar hoy y no tiene check-in;
     * incluye llegadas atrasadas), `in_house` (alojado con salida posterior a hoy),
     * `stayed_before` (check-in anterior a hoy) y la hora estimada de llegada.
     *
     * @param  list<int>|null  $roomIds
     * @return array<int, array<string, mixed>>
     */
    private function situations(int $accommodationId, Carbon $today, ?array $roomIds = null): array
    {
        $reservations = Reservation::where('accommodation_id', $accommodationId)
            ->whereNotNull('room_id')
            ->when($roomIds !== null, fn ($q) => $q->whereIn('room_id', $roomIds))
            ->whereNull('cancelled_at')->whereNull('no_show_at')->whereNull('checked_out_at')
            ->where(fn ($q) => $q->whereNotNull('checked_in_at')
                ->orWhere(fn ($q) => $q->where('checkin_date', '<=', $today->toDateString())->where('checkout_date', '>', $today->toDateString())))
            ->get();

        $out = [];

        foreach ($reservations as $reservation) {
            $s = &$out[$reservation->room_id];
            $s ??= $this->emptySituation();
            $status = $reservation->derivedStatus();

            if ($status === 'departing') {
                $s['departs'] = true;
            } elseif ($status === 'in') {
                $s['in_house'] = true;
                $checkin = $reservation->effectiveCheckin();
                $s['stayed_before'] = $s['stayed_before'] || ($checkin !== null && $checkin->lt($today));
            } elseif ($status === 'arriving') {
                $checkin = $reservation->effectiveCheckin();
                $checkout = $reservation->effectiveCheckout();
                if ($checkin !== null && $checkout !== null && $checkin->lte($today) && $checkout->gt($today)) {
                    $s['arrives'] = true;
                    $s['arrival_time'] ??= $this->arrivalTime($reservation);
                }
            }
            unset($s);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function emptySituation(): array
    {
        return ['departs' => false, 'arrives' => false, 'in_house' => false, 'stayed_before' => false, 'arrival_time' => null];
    }

    private function arrivalTime(Reservation $reservation): ?string
    {
        foreach ([$reservation->arrival_hour, $reservation->checkin_time] as $candidate) {
            if (is_string($candidate) && preg_match('/^(\d{1,2}):(\d{2})/', $candidate, $m) === 1) {
                return sprintf('%02d:%s', (int) $m[1], $m[2]);
            }
        }

        return null;
    }

    private function hasGuestInHouse(Room $room): bool
    {
        return Reservation::where('room_id', $room->id)
            ->whereNotNull('checked_in_at')->whereNull('checked_out_at')
            ->whereNull('cancelled_at')->whereNull('no_show_at')
            ->exists();
    }

    private function isDnd(Room $room, Carbon $today): bool
    {
        return $room->dnd_date !== null
            && $room->dnd_date->toDateString() === $today->toDateString()
            && in_array($room->housekeeping_status, ['oc', 'od'], true);
    }

    // ─── Piezas internas ──────────────────────────────────────────────────────

    /** Tareas abiertas que cuentan hoy: las de hoy y las manuales atrasadas. */
    private function openTasks(int $accommodationId, Carbon $today)
    {
        return HousekeepingTask::where('accommodation_id', $accommodationId)
            ->whereIn('status', [HousekeepingTask::PENDING, HousekeepingTask::IN_PROGRESS])
            ->where(fn ($q) => $q->where('date', $today->toDateString())->orWhere('type', HousekeepingTask::TYPE_MANUAL));
    }

    /** Las que muestra el tablero: todas las de hoy (menos canceladas) y las manuales abiertas atrasadas. */
    private function shownTasks(int $accommodationId, Carbon $today)
    {
        return HousekeepingTask::where('accommodation_id', $accommodationId)
            ->where('status', '<>', HousekeepingTask::CANCELLED)
            ->where(fn ($q) => $q->where('date', $today->toDateString())
                ->orWhere(fn ($q) => $q->where('type', HousekeepingTask::TYPE_MANUAL)
                    ->whereIn('status', [HousekeepingTask::PENDING, HousekeepingTask::IN_PROGRESS])));
    }

    private function createAuto(Accommodation $accommodation, Room $room, string $type, int $priority, string $date): void
    {
        try {
            $task = HousekeepingTask::create([
                'accommodation_id' => $accommodation->id,
                'room_id' => $room->id,
                'date' => $date,
                'type' => $type,
                'priority' => $priority,
                'status' => HousekeepingTask::PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra pestaña abrió la pantalla a la vez y ya la generó.
            return;
        }

        $this->event($room, 'task_created', ['task_id' => $task->id, 'source' => 'system']);
    }

    private function cancelTask(HousekeepingTask $task, string $reason): void
    {
        $task->update(['status' => HousekeepingTask::CANCELLED, 'cancelled_at' => now()]);
        $room = Room::find($task->room_id);

        if ($room !== null) {
            $this->event($room, 'task_cancelled', ['task_id' => $task->id, 'staff_id' => $task->staff_id, 'note' => $reason, 'source' => 'system']);
        }
    }

    private function closeTask(HousekeepingTask $task, Room $room, ?int $staffId): void
    {
        $task->update([
            'status' => HousekeepingTask::DONE,
            'started_at' => $task->started_at ?? now(),
            'finished_at' => now(),
            'staff_id' => $staffId ?? $task->staff_id,
        ]);
        $this->event($room, 'task_finished', ['task_id' => $task->id, 'staff_id' => $task->staff_id]);
    }

    /** Cierra las tareas de limpieza abiertas de hoy y pasa vd→vc / od→oc. */
    private function markClean(Room $room, ?int $staffId, ?HousekeepingTask $given): void
    {
        $open = $this->openTasks($room->accommodation_id, Reservation::today())
            ->where('room_id', $room->id)->where('type', '<>', HousekeepingTask::TYPE_MANUAL)->get();
        if ($given !== null && ! $open->contains('id', $given->id)) {
            $open->push($given);
        }

        foreach ($open as $task) {
            $this->closeTask($task, $room, $staffId);
        }

        $to = ['vd' => 'vc', 'od' => 'oc'][$room->housekeeping_status] ?? null;
        if ($to !== null) {
            $from = $room->housekeeping_status;
            $this->saveStatus($room, $to);
            $this->event($room, 'status_changed', [
                'task_id' => $open->first()?->id,
                'staff_id' => $staffId ?? $open->first()?->staff_id,
                'from_status' => $from, 'to_status' => $to,
            ]);
        }
    }

    private function saveStatus(Room $room, string $status): void
    {
        HousekeepingEvent::silently(function () use ($room, $status) {
            $room->housekeeping_status = $status;
            $room->save();
        });
    }

    /** @param array<string, mixed> $attributes */
    private function event(Room $room, string $type, array $attributes = []): void
    {
        $source = $attributes['source'] ?? 'user';

        HousekeepingEvent::create([
            'accommodation_id' => $room->accommodation_id,
            'room_id' => $room->id,
            'type' => $type,
            'user_id' => $source === 'user' ? Auth::id() : null,
            ...$attributes,
            'source' => $source,
        ]);
    }

    private function assertInService(Room $room): void
    {
        abort_if(
            in_array($room->housekeeping_status, ['ooo', 'oos'], true),
            409,
            'La habitación está fuera de servicio.'
        );
    }

    /** @return array{0: Room, 1: Accommodation} */
    private function roomContext(int $roomId): array
    {
        $room = Room::active()->find($roomId);
        abort_if($room === null, 404);
        $accommodation = $this->writableAccommodation($room->accommodation_id);

        return [$room, $accommodation];
    }

    private function activeRoom(Accommodation $accommodation, int $roomId): Room
    {
        $room = Room::active()->where('accommodation_id', $accommodation->id)->find($roomId);

        if ($room === null) {
            throw ValidationException::withMessages(['room_id' => 'La habitación no existe en este hotel.']);
        }

        return $room;
    }

    /** @return array{0: HousekeepingTask, 1: Accommodation, 2: Room} */
    private function taskContext(int $id): array
    {
        $task = HousekeepingTask::find($id);
        abort_if($task === null, 404);
        $accommodation = $this->writableAccommodation($task->accommodation_id);
        $room = Room::find($task->room_id);
        abort_if($room === null, 404);

        return [$task, $accommodation, $room];
    }

    private function staffOrNull(Accommodation $accommodation, mixed $staffId): ?HousekeepingStaff
    {
        if ($staffId === null || $staffId === '') {
            return null;
        }

        $staff = HousekeepingStaff::where('accommodation_id', $accommodation->id)->where('active', true)->find($staffId);

        if ($staff === null) {
            throw ValidationException::withMessages(['staff_id' => 'La persona no existe o está inactiva.']);
        }

        return $staff;
    }

    private function assertUniqueStaffName(int $accommodationId, string $name, ?int $ignoreId = null): void
    {
        $exists = HousekeepingStaff::where('accommodation_id', $accommodationId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '<>', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Ya hay una persona con ese nombre.']);
        }
    }
}
