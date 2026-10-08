<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('available');
            $table->unsignedSmallInteger('total');
            $table->boolean('closed')->default(false);
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
            $table->timestamps();

            $table->unique(['room_type_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_availability');
    }
};
