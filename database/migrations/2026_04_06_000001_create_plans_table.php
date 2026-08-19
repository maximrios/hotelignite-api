<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * En las BD restauradas del dump `plans` ya existe (`id int`, `name`,
     * `description`) con los tiers históricos. No se recrea: se agregan `slug`,
     * `enabled` y timestamps, y se backfillea el slug desde el nombre para que
     * los planes existentes sigan siendo direccionables por slug.
     */
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 100)->unique();
                $table->text('description')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });

            DB::table('plans')->insert([
                ['name' => 'Basic PMS', 'slug' => 'basic', 'description' => 'Plan básico PMS', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);

            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'slug')) {
                // nullable: MySQL admite varios NULL bajo un índice único, así
                // que el backfill posterior puede correr sin colisiones.
                $table->string('slug', 100)->nullable()->unique()->after('name');
            }
            if (! Schema::hasColumn('plans', 'enabled')) {
                $table->boolean('enabled')->default(true);
            }
            if (! Schema::hasColumn('plans', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('plans', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        DB::table('plans')
            ->where(fn ($query) => $query->whereNull('slug')->orWhere('slug', ''))
            ->get(['id', 'name'])
            ->each(fn ($plan) => DB::table('plans')
                ->where('id', $plan->id)
                ->update(['slug' => Str::slug($plan->name) ?: "plan-{$plan->id}"]));
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
