<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->enum('housekeeping_status', [
                'vc',   // Vacant Clean  — libre y lista para asignar
                'vd',   // Vacant Dirty  — libre pero sucia (post checkout)
                'oc',   // Occupied Clean
                'od',   // Occupied Dirty
                'ooo',  // Out Of Order  — fuera de servicio (no cuenta como disponible)
                'oos',  // Out Of Service — no disponible transitoriamente (mismo día)
            ])->default('vc')->after('status');

            $table->string('maintenance_note')->nullable()->after('housekeeping_status');

            $table->index('housekeeping_status');
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
