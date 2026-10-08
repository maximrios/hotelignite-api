<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base para PostgreSQL — 5/9: inventario y tarifas (tipos de
 * habitación, habitaciones físicas, planes tarifarios, precios por día y
 * disponibilidad).
 */
return new class extends Migration
{
    public function up(): void
    {
        // El nombre visible vive en `room_type_descriptions` (multilenguaje).
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accommodation_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('slug')->nullable();
            $table->decimal('size', 6, 2)->nullable();
            $table->unsignedTinyInteger('max_occupancy')->nullable();
            $table->unsignedTinyInteger('standard_occupancy')->nullable();
            $table->unsignedTinyInteger('quantity')->nullable();
            $table->unsignedTinyInteger('quantity_max')->nullable();
            $table->enum('status', ['active', 'inactive', 'maintenance'])->nullable();
            $table->timestamps();
        });

        Schema::create('room_type_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->string('language_id', 10)->default('es');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['room_type_id', 'language_id']);
        });

        Schema::create('room_type_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->unsignedBigInteger('service_id')->index();
            $table->timestamps();

            $table->unique(['room_type_id', 'service_id']);
        });

        Schema::create('room_type_beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->index()->constrained('room_types')->cascadeOnDelete();
            $table->enum('type', ['king', 'queen', 'double', 'twin', 'single', 'sofa_bed', 'bunk_bed', 'crib']);
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->timestamps();
        });

        // Habitación física. Mezcla columnas legacy (`status_id`, `condition_id`,
        // `created`/`modified`) con las del PMS nuevo (`status`, `housekeeping_status`).
        Schema::create('rooms', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('accommodation_id')->default(0);
            $table->integer('room_type_id')->default(0);
            $table->integer('status_id')->default(0);
            $table->integer('condition_id')->default(0);
            $table->string('number', 50)->default('0');
            $table->string('reference')->default('0');
            $table->integer('phone')->default(0);
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->smallInteger('enabled')->default(0);
            $table->unsignedTinyInteger('floor')->nullable();
            $table->enum('status', ['available', 'occupied', 'maintenance', 'blocked', 'checkout'])->default('available');
            $table->text('notes')->nullable();
            $table->timestamps();
            // vc vacía-limpia, vd vacía-sucia, oc/od ocupada, ooo/oos fuera de servicio.
            $table->enum('housekeeping_status', ['vc', 'vd', 'oc', 'od', 'ooo', 'oos'])->default('vc')->index();
            $table->string('maintenance_note')->nullable();
        });

        Schema::create('rate_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->index()->constrained('room_types')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->enum('cancellation', ['flexible', 'moderate', 'strict', 'non_refundable'])->default('flexible');
            $table->boolean('includes_breakfast')->default(false);
            $table->enum('meal_plan', ['ro', 'bb', 'hb', 'fb', 'ai'])->default('ro');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('price');
            $table->string('currency', 3)->default('ARS');
            $table->unsignedSmallInteger('min_stay')->default(1);
            $table->unsignedSmallInteger('max_stay')->nullable();
            $table->timestamps();

            $table->unique(['rate_plan_id', 'date']);
        });

        Schema::create('room_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('available');
            $table->unsignedSmallInteger('total');
            $table->boolean('closed')->default(false);
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
            $table->unsignedSmallInteger('min_stay')->default(1);
            $table->unsignedSmallInteger('max_stay')->nullable();
            $table->timestamps();

            $table->unique(['room_type_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_availability');
        Schema::dropIfExists('rates');
        Schema::dropIfExists('rate_plans');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_type_beds');
        Schema::dropIfExists('room_type_services');
        Schema::dropIfExists('room_type_descriptions');
        Schema::dropIfExists('room_types');
    }
};
