<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: la tabla `reservations` legacy (del dump) ya trae algunas
        // columnas equivalentes (ej. `adults`). Se agrega sólo lo que falte para
        // no chocar con "Duplicate column" al aplicar sobre el esquema legacy.
        Schema::table('reservations', function (Blueprint $table) {
            $missing = fn (string $col): bool => ! Schema::hasColumn('reservations', $col);

            // Identificación visible al huésped
            if ($missing('confirmation_number')) {
                $table->string('confirmation_number')->nullable()->unique()->after('id');
            }
            if ($missing('source_reference')) {
                $table->string('source_reference')->nullable()->after('id'); // nro. en OTA origen
            }

            // Fechas de estancia (separadas de las legacy arrival/departure/checkin)
            if ($missing('checkin_date')) {
                $table->date('checkin_date')->nullable()->after('room_id');
            }
            if ($missing('checkout_date')) {
                $table->date('checkout_date')->nullable()->after('room_id');
            }
            if ($missing('checkin_time')) {
                $table->time('checkin_time')->nullable()->after('room_id');
            }
            if ($missing('checkout_time')) {
                $table->time('checkout_time')->nullable()->after('room_id');
            }

            // Ocupación (adults ya existe en el legacy; children/extra_beds no)
            if ($missing('adults')) {
                $table->unsignedTinyInteger('adults')->default(1)->after('room_id');
            }
            if ($missing('children')) {
                $table->unsignedTinyInteger('children')->default(0)->after('room_id');
            }
            if ($missing('extra_beds')) {
                $table->unsignedTinyInteger('extra_beds')->default(0)->after('room_id');
            }

            // Tarifa aplicada (en centavos)
            if ($missing('rate_plan_id')) {
                $table->foreignId('rate_plan_id')->nullable()->constrained()->nullOnDelete();
            }
            if ($missing('rate_amount')) {
                $table->unsignedInteger('rate_amount')->nullable();
            }
            if ($missing('total_amount')) {
                $table->unsignedInteger('total_amount')->nullable();
            }
            if ($missing('currency')) {
                $table->string('currency', 3)->default('ARS');
            }
            if ($missing('commission_amount')) {
                $table->unsignedInteger('commission_amount')->nullable();
            }

            // Garantía y depósito
            if ($missing('guarantee_type')) {
                $table->enum('guarantee_type', ['credit_card', 'deposit', 'agency_voucher', 'none'])->default('none');
            }
            if ($missing('deposit_amount')) {
                $table->unsignedInteger('deposit_amount')->nullable();
            }
            if ($missing('deposit_date')) {
                $table->date('deposit_date')->nullable();
            }

            // Cancelación
            if ($missing('cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if ($missing('cancellation_reason')) {
                $table->string('cancellation_reason')->nullable();
            }

            // Observaciones
            if ($missing('special_requests')) {
                $table->text('special_requests')->nullable();
            }
            if ($missing('internal_notes')) {
                $table->text('internal_notes')->nullable();
            }
        });

        // Índices para reporting (idempotentes vía information_schema)
        foreach (['checkin_date', 'checkout_date', 'status_id'] as $col) {
            $indexName = "reservations_{$col}_index";
            $exists = DB::table('information_schema.statistics')
                ->where('table_schema', DB::raw('DATABASE()'))
                ->where('table_name', 'reservations')
                ->where('index_name', $indexName)
                ->exists();

            if (! $exists && Schema::hasColumn('reservations', $col)) {
                Schema::table('reservations', fn (Blueprint $table) => $table->index($col, $indexName));
            }
        }
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['rate_plan_id']);
            $table->dropIndex(['checkin_date']);
            $table->dropIndex(['checkout_date']);
            $table->dropIndex(['status_id']);
            $table->dropColumn([
                'confirmation_number', 'source_reference',
                'checkin_date', 'checkout_date', 'checkin_time', 'checkout_time',
                'adults', 'children', 'extra_beds',
                'rate_plan_id', 'rate_amount', 'total_amount', 'currency', 'commission_amount',
                'guarantee_type', 'deposit_amount', 'deposit_date',
                'cancelled_at', 'cancellation_reason',
                'special_requests', 'internal_notes',
            ]);
        });
    }
};
