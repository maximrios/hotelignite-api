<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_rate_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')
                    ->constrained()
                    ->cascadeOnDelete();

            $table->enum('rate_type', ['flexible', 'semi_flexible', 'non_refundable']);
            $table->unsignedTinyInteger('cancel_days')->nullable();
            $table->decimal('cancel_penalty', 5, 2)->nullable();
            $table->decimal('no_show_penalty', 5, 2)->nullable();

            $table->unique(['accommodation_id', 'rate_type']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_rate_policies');
    }
};
