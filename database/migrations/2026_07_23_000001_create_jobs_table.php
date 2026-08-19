<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de la cola con driver `database` (`QUEUE_CONNECTION=database`).
     *
     * El proyecto ya tenía `failed_jobs` pero no `jobs`, así que encolar (p. ej.
     * `Mail::queue(new InvitationMail(...))`, ver InvitationRepository::emit)
     * fallaba: el driver `database` empuja los jobs acá. Requiere un worker
     * (`php artisan queue:work`) corriendo para procesarlos.
     *
     * `job_batches` no se incluye: no se usan lotes (`Bus::batch`). Agregarla si
     * en algún momento se necesitan.
     */
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
