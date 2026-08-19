<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acotamiento opcional de un user de tipo `account` a alojamientos puntuales.
     *
     * Modelo híbrido: `users.account_id` sigue siendo el tenant / hogar de
     * facturación y da la visibilidad por defecto (todos los alojamientos de la
     * cuenta, en vivo). Este pivote es el *narrowing* explícito: si un user tiene
     * filas acá, ve exactamente esos alojamientos en vez de toda la cuenta
     * (ver Accommodation::scopeVisibleTo). Espejo simétrico de
     * `accommodation_client`, que hace lo mismo para los clients B2B.
     *
     * Sin FK, igual que `accommodation_client`: `accommodations.id` es `bigint`
     * con signo en la BD legacy (restaurada de dumps), incompatible con el
     * `bigint unsigned` de `foreignId()`. La integridad se hace cumplir en la
     * app (FormRequests + sync del controller), no en la BD.
     */
    public function up(): void
    {
        Schema::create('accommodation_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('accommodation_id')->index();
            $table->timestamps();
            $table->unique(['user_id', 'accommodation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_user');
    }
};
