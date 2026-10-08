<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('price'); // en centavos
            $table->string('currency', 3)->default('ARS');
            $table->unsignedSmallInteger('min_stay')->default(1);
            $table->unsignedSmallInteger('max_stay')->nullable();
            $table->timestamps();

            $table->unique(['rate_plan_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rates');
    }
};
