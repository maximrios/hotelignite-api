<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * En las BD restauradas del dump `accounts` ya existe con 78 filas y con
     * dos columnas nombradas distinto a lo que espera el modelo `Account`:
     * `expiration_test` → `expiration_date` y `comment` → `comments`.
     * Se renombran preservando los datos (SQL crudo: el proyecto no tiene
     * doctrine/dbal, requerido por `renameColumn`).
     *
     * Sin FKs a `plans` / `account_types`: en el legacy esos ids son `int`,
     * incompatibles con el `bigint unsigned` de `foreignId()`.
     */
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            Schema::create('accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('plan_id')->nullable()->index();
                $table->unsignedBigInteger('account_type_id')->nullable()->index();
                $table->string('name', 255);
                $table->string('first_name', 100)->nullable();
                $table->string('last_name', 100)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('phone', 50)->nullable();
                $table->boolean('active')->default(true);
                $table->boolean('test')->default(false);
                $table->date('expiration_date')->nullable();
                $table->text('comments')->nullable();
                $table->boolean('agreement')->default(false);
                $table->string('token', 100)->nullable()->unique();
                $table->timestamps();
                $table->softDeletes();
            });

            return;
        }

        if (Schema::hasColumn('accounts', 'expiration_test') && ! Schema::hasColumn('accounts', 'expiration_date')) {
            DB::statement('ALTER TABLE `accounts` CHANGE `expiration_test` `expiration_date` DATE NULL');
        }

        if (Schema::hasColumn('accounts', 'comment') && ! Schema::hasColumn('accounts', 'comments')) {
            DB::statement('ALTER TABLE `accounts` CHANGE `comment` `comments` TEXT NULL');
        }

        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'expiration_date')) {
                $table->date('expiration_date')->nullable();
            }
            if (! Schema::hasColumn('accounts', 'comments')) {
                $table->text('comments')->nullable();
            }
            if (! Schema::hasColumn('accounts', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
