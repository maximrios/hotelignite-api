<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();

            // Horarios
            $table->time('checkin_from')->nullable();
            $table->time('checkin_to')->nullable();
            $table->time('checkout_from')->nullable();
            $table->time('checkout_to')->nullable();

            // Huéspedes
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->boolean('allow_children')->default(false);
            $table->unsignedTinyInteger('children_max_age')->nullable();
            $table->boolean('allow_pets')->default(false);
            $table->boolean('allow_smoking')->default(false);
            $table->boolean('allow_parties')->default(false);

            // Formas de pago
            $table->boolean('payment_card')->default(false);
            $table->boolean('payment_cash')->default(false);
            $table->boolean('payment_transfer')->default(false);
            $table->boolean('payment_crypto')->default(false);

            $table->timestamps();
      });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_policies');
    }
};
