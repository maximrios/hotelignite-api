<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuotas de socios: lo que el client (una cámara) le cobra a sus asociados.
 * NO es la facturación de HotelIgnite (`subscriptions`/`payments`/`invoices`):
 * acá cobra el client, emite su propio recibo y la plata nunca pasa por
 * nosotros. HotelIgnite sólo lleva el libro.
 *
 * - Polimórfico sobre el asociado (`member_type` + `member_id`, ver
 *   `App\Support\AssociateType`): cuelga del vínculo client ↔ asociado, no del
 *   pivote de alojamientos, para que los prestadores entren sin migrar nada.
 * - `member_ledger_entries` es un libro: no se edita ni se borra. Un error se
 *   corrige con un asiento `reversal` que apunta al original.
 * - Montos en centavos, con signo: positivo = debe (cuota, cargo), negativo =
 *   haber (pago, bonificación). El saldo es la suma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name', 80);
            $table->bigInteger('amount');
            // Día del mes en que vence la cuota emitida para ese mes.
            $table->unsignedTinyInteger('due_day')->default(10);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['client_id', 'active']);
        });

        Schema::create('member_fee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('member_type', 32);
            $table->unsignedBigInteger('member_id');
            $table->foreignId('membership_fee_id')->constrained('membership_fees')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'member_type', 'member_id'], 'member_fee_assignment_unique');
        });

        Schema::create('member_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('member_type', 32);
            $table->unsignedBigInteger('member_id');
            $table->string('kind', 16); // charge | payment | credit | reversal
            $table->string('concept', 160);
            $table->bigInteger('amount');
            $table->char('period', 7)->nullable(); // YYYY-MM, sólo cuotas emitidas
            $table->date('occurred_on');
            $table->date('due_on')->nullable();
            $table->string('method', 20)->nullable(); // transferencia | efectivo | ...
            $table->string('reference', 60)->nullable(); // n.º de recibo del client
            $table->foreignId('membership_fee_id')->nullable()->constrained('membership_fees')->nullOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('member_ledger_entries')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['client_id', 'member_type', 'member_id', 'occurred_on'], 'member_ledger_member_idx');
        });

        // Sin índice único por (asociado, período): una cuota mal emitida se anula
        // y se vuelve a emitir. La emisión evita duplicar con un lock por client
        // (`DuesRepository::emit`).
    }

    public function down(): void
    {
        Schema::dropIfExists('member_ledger_entries');
        Schema::dropIfExists('member_fee_assignments');
        Schema::dropIfExists('membership_fees');
    }
};
