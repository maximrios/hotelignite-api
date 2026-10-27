<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contactos que deja la landing pública (`web/`): un hotelero que quiere
 * empezar gratis (`signup`) o pide una demo (`demo`). No crea cuentas: el alta
 * es asistida por el staff, que lo contacta y lo da de alta en el CRM.
 *
 * No es `inquiries`: esa tabla es la consulta de un viajero sobre un
 * alojamiento; esto es un prospecto de HotelIgnite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();
            $table->string('status', 20)->default('new')->index();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->string('accommodation_name')->nullable();
            $table->string('city')->nullable();
            $table->unsignedSmallInteger('rooms')->nullable();
            $table->text('message')->nullable();
            $table->string('source', 20)->default('web');
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
