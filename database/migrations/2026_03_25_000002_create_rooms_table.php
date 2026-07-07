<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->unsignedTinyInteger('floor')->nullable();
            $table->enum('status', ['available', 'occupied', 'maintenance', 'blocked', 'checkout'])->default('available');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['accommodation_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
