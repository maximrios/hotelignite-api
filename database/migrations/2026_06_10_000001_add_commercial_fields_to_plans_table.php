<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotente por columna: `plans` viene del dump legacy y se completa de
     * forma incremental a lo largo de estas migraciones.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $missing = fn (string $col): bool => ! Schema::hasColumn('plans', $col);

            if ($missing('subtitle')) {
                $table->string('subtitle', 150)->nullable()->after('name');     // copy comercial del tier
            }
            if ($missing('price')) {
                $table->unsignedInteger('price')->default(0)->after('description'); // en centavos
            }
            if ($missing('currency')) {
                $table->char('currency', 3)->default('ARS')->after('price');
            }
            if ($missing('billing_period')) {
                $table->enum('billing_period', ['free', 'monthly', 'yearly'])
                      ->default('free')->after('currency');
            }
            if ($missing('trial_days')) {
                $table->unsignedSmallInteger('trial_days')->default(0)->after('billing_period');
            }
            if ($missing('sort_order')) {
                $table->unsignedSmallInteger('sort_order')->default(0)->after('trial_days');
            }
            if ($missing('is_public')) {
                $table->boolean('is_public')->default(true)->after('sort_order'); // visible en pricing público
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'subtitle', 'price', 'currency', 'billing_period',
                'trial_days', 'sort_order', 'is_public',
            ]);
        });
    }
};
