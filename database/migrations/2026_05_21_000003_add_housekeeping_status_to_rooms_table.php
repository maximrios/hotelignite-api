<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotente y sin ->after('status'): la tabla `rooms` legacy usa
        // `status_id`, no una columna `status`. Se agrega al final.
        Schema::table('rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('rooms', 'housekeeping_status')) {
                $table->enum('housekeeping_status', [
                    'vc',   // Vacant Clean  — libre y lista para asignar
                    'vd',   // Vacant Dirty  — libre pero sucia (post checkout)
                    'oc',   // Occupied Clean
                    'od',   // Occupied Dirty
                    'ooo',  // Out Of Order  — fuera de servicio (no cuenta como disponible)
                    'oos',  // Out Of Service — no disponible transitoriamente (mismo día)
                ])->default('vc');

                $table->index('housekeeping_status');
            }

            if (! Schema::hasColumn('rooms', 'maintenance_note')) {
                $table->string('maintenance_note')->nullable()->after('housekeeping_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex(['housekeeping_status']);
            $table->dropColumn(['housekeeping_status', 'maintenance_note']);
        });
    }
};
