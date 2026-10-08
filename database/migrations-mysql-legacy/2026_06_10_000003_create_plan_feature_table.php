<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `plan_id` sin FK: en la BD legacy `plans.id` es `int`, incompatible con el
     * `bigint unsigned` de `foreignId()`. `features` sí se crea por migración,
     * así que esa FK se mantiene.
     */
    public function up(): void
    {
        if (Schema::hasTable('plan_feature')) {
            return;
        }

        Schema::create('plan_feature', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id')->index();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->integer('limit')->nullable(); // null = ilimitado; para type='boolean' se ignora
            $table->timestamps();
            $table->unique(['plan_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_feature');
    }
};
