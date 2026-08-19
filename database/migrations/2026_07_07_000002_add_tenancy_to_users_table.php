<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenencia en users. Un user pertenece a un account (CRUD sobre sus
     * accommodations), a un client (solo lectura de accommodations
     * relacionados) o es platform (super-admin, bypassa la tenencia).
     * Regla: exactamente uno de account_id/client_id seteado, o ambos null
     * para platform.
     *
     * Sin FK a nivel DB: la tabla legacy `accounts` usa `id int` / latin1
     * (viene de un dump, no de migraciones), incompatible con una FK bigint.
     * La integridad referencial se hace cumplir en la app (Policies), no en la BD.
     */
    public function up(): void
    {
        // Idempotente por columna: la DB legacy (restaurada de dumps) puede ya
        // tener alguna de estas columnas (ej. `account_id`). Se agrega sólo lo
        // que falte para evitar "Duplicate column" al re-aplicar sobre el dump.
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'user_type')) {
                $table->string('user_type', 20)->default('account')->after('email');
            }
            if (! Schema::hasColumn('users', 'account_id')) {
                $table->unsignedBigInteger('account_id')->nullable()->after('user_type')->index();
            }
            if (! Schema::hasColumn('users', 'client_id')) {
                $table->unsignedBigInteger('client_id')->nullable()->after('account_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['user_type', 'account_id', 'client_id']);
        });
    }
};
