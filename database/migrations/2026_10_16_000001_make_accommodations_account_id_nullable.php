<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `accommodations.account_id` pasa a nullable: `null` es "borrador", un
 * alojamiento sin dueño todavía (portal-asociados, estados derivados).
 *
 * `InvitationRepository` ya detectaba el borrador con `account_id === null`,
 * pero la columna era `NOT NULL DEFAULT 0` y el modelo convertía `null` en `0`:
 * en Postgres el borrador no podía existir y el accept caía en la rama
 * `consent` en vez de `claim`. Las filas con `0` (huérfanas, no hay cuenta 0)
 * pasan a `null`, que es lo que siempre quisieron decir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->integer('account_id')->nullable()->default(null)->change();
        });

        DB::table('accommodations')->where('account_id', 0)->update(['account_id' => null]);
    }

    public function down(): void
    {
        DB::table('accommodations')->whereNull('account_id')->update(['account_id' => 0]);

        Schema::table('accommodations', function (Blueprint $table) {
            $table->integer('account_id')->default(0)->nullable(false)->change();
        });
    }
};
