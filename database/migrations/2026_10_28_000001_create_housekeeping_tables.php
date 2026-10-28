<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping del PMS (acordado con hotelería).
 *
 * - `rooms.inspected_at`: se limpia al pasar a sucia (vd/od) y se completa al
 *   inspeccionar. `rooms.dnd_date`: "no molestar" del día. `rooms.out_of_service_until`:
 *   retorno estimado de un fuera de servicio.
 * - `accommodations.housekeeping_requires_inspection`: con "sí", una vacía-limpia
 *   sin inspeccionar se muestra "limpia, por inspeccionar".
 * - `housekeeping_staff`: personal de limpieza SIN login (sólo un nombre a quien
 *   se asigna trabajo). `housekeeping_tasks`: una tarea por habitación + día + tipo.
 *   `housekeeping_events`: historial inmutable por habitación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamp('inspected_at')->nullable();
            $table->date('dnd_date')->nullable();
            $table->date('out_of_service_until')->nullable();
        });

        Schema::table('accommodations', function (Blueprint $table) {
            $table->boolean('housekeeping_requires_inspection')->default(false);
        });

        Schema::create('housekeeping_staff', function (Blueprint $table) {
            $table->id();
            $table->integer('accommodation_id');
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('accommodation_id')->references('id')->on('accommodations')->cascadeOnDelete();
            $table->index(['accommodation_id', 'active']);
        });

        Schema::create('housekeeping_tasks', function (Blueprint $table) {
            $table->id();
            $table->integer('accommodation_id');
            $table->integer('room_id');
            $table->date('date');
            // checkout | arrival | daily | manual
            $table->string('type', 20);
            // 1 rotación · 2 llega hoy · 3 salida / manual · 4 repaso
            $table->unsignedSmallInteger('priority')->default(3);
            // pending | in_progress | done | cancelled
            $table->string('status', 20)->default('pending');
            $table->foreignId('staff_id')->nullable()->constrained('housekeeping_staff')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('accommodation_id')->references('id')->on('accommodations')->cascadeOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
            $table->index(['accommodation_id', 'date']);
            $table->index(['room_id', 'date']);
        });

        // Una automática por habitación + día + tipo; las manuales pueden repetirse.
        DB::statement("CREATE UNIQUE INDEX housekeeping_tasks_auto_unique ON housekeeping_tasks (room_id, date, type) WHERE type <> 'manual'");

        Schema::create('housekeeping_events', function (Blueprint $table) {
            $table->id();
            $table->integer('accommodation_id');
            $table->integer('room_id');
            $table->foreignId('task_id')->nullable()->constrained('housekeeping_tasks')->nullOnDelete();
            $table->string('type', 30);
            $table->string('from_status', 5)->nullable();
            $table->string('to_status', 5)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('housekeeping_staff')->nullOnDelete();
            // user = lo hizo una persona desde housekeeping; system = efecto de recepción/inventario/generación.
            $table->string('source', 10)->default('user');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('accommodation_id')->references('id')->on('accommodations')->cascadeOnDelete();
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
            $table->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_events');
        Schema::dropIfExists('housekeeping_tasks');
        Schema::dropIfExists('housekeeping_staff');

        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn('housekeeping_requires_inspection');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['inspected_at', 'dnd_date', 'out_of_service_until']);
        });
    }
};
