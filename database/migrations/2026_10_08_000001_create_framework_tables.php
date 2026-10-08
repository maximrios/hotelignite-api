<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base para PostgreSQL — 1/9: tablas del framework.
 *
 * Este set (`2026_10_08_*`) reemplaza a las 75 migraciones viejas, que quedaron
 * archivadas en `database/migrations-mysql-legacy/` como referencia histórica.
 * Aquellas no corrían desde cero (SQL crudo de MySQL, columnas generadas,
 * `MODIFY`, `information_schema`); éstas reconstruyen el esquema real de la base
 * MySQL de dev tal como estaba el 2026-10-08, escritas sólo con el Schema builder.
 *
 * Criterios de traducción que aplican a todo el set:
 * - `char(n)` → `varchar(n)`: en Postgres `char` rellena con espacios y los
 *   devuelve ('es' sale como 'es  '); MySQL los recortaba al leer.
 * - `tinyint(1)` usado como flag → `boolean` cuando el modelo lo castea o el
 *   código lo consulta con true/false. Los `tinyint` legacy sin cast quedan
 *   `smallint`: pasarlos a boolean cambiaría `1` por `true` en la API.
 * - Fechas `NOT NULL DEFAULT '0000-00-00'` → nullable sin default (Postgres no
 *   acepta fechas cero; en la copia de datos se vuelven NULL).
 * - Postgres no crea índices para las FK (MySQL sí): los que MySQL creaba
 *   implícitamente están declarados a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            // platform | client | account — ver CLAUDE.md raíz.
            $table->string('user_type', 20)->default('account');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->integer('account_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->integer('accommodation_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // MySQL tenía la `password_resets` de Laravel 8 (vacía), pero
        // `config/auth.php` apunta a `password_reset_tokens`.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
