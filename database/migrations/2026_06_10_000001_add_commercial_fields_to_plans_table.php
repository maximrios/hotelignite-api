<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('subtitle', 150)->nullable()->after('name');     // copy comercial del tier
            $table->unsignedInteger('price')->default(0)->after('description'); // en centavos
            $table->char('currency', 3)->default('ARS')->after('price');
            $table->enum('billing_period', ['free', 'monthly', 'yearly'])
                  ->default('free')->after('currency');
            $table->unsignedSmallInteger('trial_days')->default(0)->after('billing_period');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('trial_days');
            $table->boolean('is_public')->default(true)->after('sort_order'); // visible en pricing público
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
