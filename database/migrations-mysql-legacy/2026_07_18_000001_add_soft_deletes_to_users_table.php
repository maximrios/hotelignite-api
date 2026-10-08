<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baja lógica de usuarios (ver docs/users-crud-plan.md, "Decisiones abiertas").
     * Se conserva el registro para no romper relaciones ni el histórico, y el
     * trait SoftDeletes lo excluye de toda query — incluida la del guard de
     * autenticación, así que un usuario dado de baja tampoco puede loguearse.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
