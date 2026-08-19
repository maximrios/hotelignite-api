<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * En las BD restauradas del dump, `rooms` ya existe con el esquema legacy
     * (`id int`, `status_id`, `condition_id`, `created`/`modified`) y con datos.
     * No se recrea — se agregan sólo las columnas que el modelo `Room` necesita
     * (`floor`, `status`, `notes`, timestamps de Eloquent).
     *
     * En el camino legacy no se crea el índice único (accommodation_id, number):
     * los datos históricos no lo garantizan y la migración fallaría. Queda como
     * regla de la app hasta que se normalicen las habitaciones existentes.
     */
    public function up(): void
    {
        if (! Schema::hasTable('rooms')) {
            Schema::create('rooms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('room_type_id')->index();
                $table->unsignedBigInteger('accommodation_id')->index();
                $table->string('number');
                $table->unsignedTinyInteger('floor')->nullable();
                $table->enum('status', ['available', 'occupied', 'maintenance', 'blocked', 'checkout'])->default('available');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['accommodation_id', 'number']);
            });

            return;
        }

        Schema::table('rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('rooms', 'floor')) {
                $table->unsignedTinyInteger('floor')->nullable();
            }
            if (! Schema::hasColumn('rooms', 'status')) {
                $table->enum('status', ['available', 'occupied', 'maintenance', 'blocked', 'checkout'])
                    ->default('available');
            }
            if (! Schema::hasColumn('rooms', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (! Schema::hasColumn('rooms', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('rooms', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
