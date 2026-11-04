<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Caja del hotel (feature `cash_register`, plan Básico en adelante). Diseño
 * acordado con hotelería el 2026-11-03, ver `api/docs/pms-modules-roadmap.md`.
 *
 * - `cash_registers`: la caja física. Hoy una por alojamiento ("Recepción"),
 *   creada al primer uso; la tabla existe para sumar más sin migrar.
 * - `cash_shifts`: el turno. Se abre con fondo y se cierra con arqueo. Uno solo
 *   abierto por alojamiento (índice único parcial, no sólo validación).
 * - `cash_shift_balances`: fondo, esperado, contado y diferencia por moneda. No se
 *   recalculan después del cierre.
 * - `cash_categories`: por alojamiento, editables. Las de sistema tienen `code`
 *   fijo (la lógica las busca por código) y no se borran.
 * - `cash_entries`: el libro. No se edita ni se borra; un error se anula con un
 *   asiento inverso (`reverses_entry_id`, único). El monto se guarda positivo y
 *   el sentido va en `direction` (in = ingreso, out = egreso). Centavos.
 *
 * El arqueo cuenta sólo efectivo. Los cobros que no son efectivo pueden entrar
 * sin turno abierto (`cash_shift_id` null): una seña por transferencia que llega
 * de noche no espera a que alguien abra la caja.
 *
 * Distinto de `payments`/`invoices`, que son la facturación de HotelIgnite al
 * hotel. Acá el dinero es del hotel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained('accommodations')->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['accommodation_id', 'active']);
        });

        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained('accommodations')->cascadeOnDelete();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();
            $table->index(['accommodation_id', 'opened_at']);
        });

        // Un solo turno abierto por alojamiento.
        DB::statement('CREATE UNIQUE INDEX cash_shifts_one_open_per_accommodation ON cash_shifts (accommodation_id) WHERE closed_at IS NULL');

        Schema::create('cash_shift_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_shift_id')->constrained('cash_shifts')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('opening_amount')->default(0);
            // Se completan al cerrar.
            $table->bigInteger('expected_amount')->nullable();
            $table->bigInteger('counted_amount')->nullable();
            $table->bigInteger('difference')->nullable();
            $table->timestamps();
            $table->unique(['cash_shift_id', 'currency']);
        });

        Schema::create('cash_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained('accommodations')->cascadeOnDelete();
            $table->string('code', 32)->nullable(); // de sistema; null = creada por el hotel
            $table->string('name', 80);
            $table->string('direction', 3); // in | out
            // operating = resultado del hotel; treasury = retiros, aportes, depósitos
            // (mueven efectivo pero no son ingreso ni gasto); adjustment = arqueo.
            $table->string('group', 16)->default('operating');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['accommodation_id', 'code']);
            $table->index(['accommodation_id', 'active']);
        });

        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained('accommodations')->cascadeOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->restrictOnDelete();
            $table->foreignId('cash_category_id')->constrained('cash_categories')->restrictOnDelete();
            // Sin FK: `reservations.id` es un varchar legacy y el libro no debe
            // impedir ni seguir operaciones sobre la reserva.
            $table->string('reservation_id', 36)->nullable()->index();
            $table->string('direction', 3); // in | out
            $table->bigInteger('amount'); // positivo, centavos, en `currency`
            $table->char('currency', 3);
            $table->string('method', 20); // cash | transfer | debit | credit | mercadopago | other
            // Equivalente en la moneda de la reserva cuando difiere (lo carga el usuario).
            $table->bigInteger('reservation_amount')->nullable();
            $table->string('concept', 160)->nullable();
            $table->string('reference', 60)->nullable(); // n.º de operación, cupón, recibo
            $table->date('occurred_on'); // fecha del dinero; created_at es la de carga
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('cash_entries')->restrictOnDelete();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['accommodation_id', 'idempotency_key']);
            $table->index(['accommodation_id', 'occurred_on']);
            $table->index(['cash_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('cash_categories');
        Schema::dropIfExists('cash_shift_balances');
        Schema::dropIfExists('cash_shifts');
        Schema::dropIfExists('cash_registers');
    }
};
