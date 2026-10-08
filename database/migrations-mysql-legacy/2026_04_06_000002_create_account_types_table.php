<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * En las BD restauradas del dump `account_types` ya existe con sus tipos
     * históricos (Property, Group, Rental Property, Rentals Group). No se
     * recrea ni se re-siembra: sólo se completan los timestamps de Eloquent.
     */
    public function up(): void
    {
        if (! Schema::hasTable('account_types')) {
            Schema::create('account_types', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->timestamps();
            });

            DB::table('account_types')->insert([
                ['name' => 'Propiedad individual', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Grupo hotelero',       'created_at' => now(), 'updated_at' => now()],
            ]);

            return;
        }

        Schema::table('account_types', function (Blueprint $table) {
            if (! Schema::hasColumn('account_types', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('account_types', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_types');
    }
};
