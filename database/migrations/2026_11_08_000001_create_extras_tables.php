<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extras vendibles de cada alojamiento (desayuno, cochera, traslado…) y las líneas que
 * quedan en la reserva. La línea guarda nombre, modalidad y precio del momento: si el
 * hotel cambia el extra después, la reserva no se mueve. Ver docs/extras.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            // Plantilla de la que salió (breakfast, parking…); null = extra propio del hotel.
            $table->string('template', 40)->nullable();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('price')->default(0); // centavos
            $table->string('charge_mode', 20); // stay | night | person | person_night
            $table->unsignedSmallInteger('max_quantity')->default(1);
            $table->boolean('price_includes_tax')->default(true);
            $table->boolean('requires_confirmation')->default(true);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['accommodation_id', 'enabled']);
        });

        Schema::create('reservation_extras', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_id', 36);
            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
            $table->foreignId('extra_id')->nullable()->constrained('extras')->nullOnDelete();
            $table->string('template', 40)->nullable();
            $table->string('name', 120);
            $table->string('charge_mode', 20);
            $table->unsignedBigInteger('unit_price'); // centavos, por unidad de la modalidad
            $table->unsignedSmallInteger('quantity');
            // Noches, personas o personas × noches según la modalidad, al momento de pedirlo.
            $table->unsignedInteger('units');
            $table->unsignedBigInteger('total_amount'); // unit_price × units × quantity
            $table->boolean('requires_confirmation')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_extras');
        Schema::dropIfExists('extras');
    }
};
