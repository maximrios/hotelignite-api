<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Identificación visible al huésped
            $table->string('confirmation_number')->nullable()->unique()->after('id');
            $table->string('source_reference')->nullable()->after('confirmation_number'); // nro. en OTA origen

            // Fechas de estancia
            $table->date('checkin_date')->nullable()->after('room_id');
            $table->date('checkout_date')->nullable()->after('checkin_date');
            $table->time('checkin_time')->nullable()->after('checkout_date');
            $table->time('checkout_time')->nullable()->after('checkin_time');

            // Ocupación
            $table->unsignedTinyInteger('adults')->default(1)->after('checkout_time');
            $table->unsignedTinyInteger('children')->default(0)->after('adults');
            $table->unsignedTinyInteger('extra_beds')->default(0)->after('children');

            // Tarifa aplicada (en centavos)
            $table->foreignId('rate_plan_id')->nullable()->constrained()->nullOnDelete()->after('extra_beds');
            $table->unsignedInteger('rate_amount')->nullable()->after('rate_plan_id');
            $table->unsignedInteger('total_amount')->nullable()->after('rate_amount');
            $table->string('currency', 3)->default('ARS')->after('total_amount');
            $table->unsignedInteger('commission_amount')->nullable()->after('currency');

            // Garantía y depósito
            $table->enum('guarantee_type', ['credit_card', 'deposit', 'agency_voucher', 'none'])
                  ->default('none')->after('commission_amount');
            $table->unsignedInteger('deposit_amount')->nullable()->after('guarantee_type');
            $table->date('deposit_date')->nullable()->after('deposit_amount');

            // Cancelación
            $table->timestamp('cancelled_at')->nullable()->after('deposit_date');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');

            // Observaciones
            $table->text('special_requests')->nullable()->after('cancellation_reason');
            $table->text('internal_notes')->nullable()->after('special_requests');

            // Índices para reporting y búsquedas frecuentes
            $table->index('checkin_date');
            $table->index('checkout_date');
            $table->index('status_id');
        });
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
