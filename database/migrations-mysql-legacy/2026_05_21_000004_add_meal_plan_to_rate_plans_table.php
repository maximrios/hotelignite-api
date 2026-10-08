<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            // ro = Room Only, bb = Bed & Breakfast, hb = Half Board,
            // fb = Full Board, ai = All Inclusive
            $table->enum('meal_plan', ['ro', 'bb', 'hb', 'fb', 'ai'])
                  ->default('ro')->after('includes_breakfast');
        });

        // Migrar datos existentes: includes_breakfast=true → bb, resto → ro
        DB::table('rate_plans')->where('includes_breakfast', true)->update(['meal_plan' => 'bb']);
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('meal_plan');
        });
    }
};
