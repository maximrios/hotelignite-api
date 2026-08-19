<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sin FK a `accommodations`: en la BD legacy su `id` es `bigint` con signo,
     * incompatible con el `bigint unsigned` de `foreignId()`.
     */
    public function up(): void
    {
        if (Schema::hasTable('accommodation_rate_policies')) {
            return;
        }

        Schema::create('accommodation_rate_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accommodation_id')->index();

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
