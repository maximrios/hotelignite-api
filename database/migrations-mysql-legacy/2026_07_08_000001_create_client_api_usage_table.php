<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uso diario agregado por client (para facturar por volumen). Se incrementa
     * una fila por client/día con un upsert atómico en `AuthenticateApiClient`.
     * Barato: una escritura indexada por request.
     */
    public function up(): void
    {
        Schema::create('client_api_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['client_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_api_usage');
    }
};
