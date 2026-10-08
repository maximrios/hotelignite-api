<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base para PostgreSQL — 3/9: tenants.
 *
 * - `accounts`: el hotelero (`user_type: account`), dueño de alojamientos.
 * - `clients`: el cliente B2B (`user_type: client`) — agencias, gobiernos — con
 *   sus API keys y su contador diario de uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            // Default/facturación; los permisos salen de `accommodations.plan_id`.
            $table->integer('plan_id')->nullable();
            $table->integer('account_type_id')->nullable();
            $table->string('name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('active')->nullable();
            $table->boolean('test')->nullable();
            $table->date('expiration_date')->nullable();
            $table->text('comments')->nullable();
            // Guarda 'monthly'/'yearly' aunque el modelo lo castee a boolean.
            $table->string('agreement', 50)->nullable();
            // Secreto de la cuenta: no exponerlo en Resources.
            $table->string('token')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 50)->nullable();
            $table->integer('channel_id')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
        });

        Schema::create('client_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->index()->constrained('clients')->cascadeOnDelete();
            $table->string('name', 100)->nullable();
            $table->string('prefix', 32)->unique();
            $table->string('key_hash', 64);
            $table->jsonb('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_api_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamps();

            // Destino del upsert de `ClientApiUsage::hit()`.
            $table->unique(['client_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_api_usage');
        Schema::dropIfExists('client_api_keys');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('accounts');
    }
};
