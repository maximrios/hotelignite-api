<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clients externos (agencias, gobiernos, empresas) que leen datos de
     * accommodations relacionados. La relación con accommodations es M2M
     * (pivote `accommodation_client`). Los endpoints de client se cablean
     * en una fase posterior — acá solo se prepara el schema.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('type', 50)->nullable(); // agency | government | company
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('accommodation_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained('accommodations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['accommodation_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_client');
        Schema::dropIfExists('clients');
    }
};
