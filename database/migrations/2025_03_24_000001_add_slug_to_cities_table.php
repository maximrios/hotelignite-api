<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: el snapshot de `cities` ya puede incluir `slug`.
        if (Schema::hasColumn('cities', 'slug')) {
            return;
        }

        Schema::table('cities', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
        });

        DB::table('cities')->get()->each(function ($city) {
            DB::table('cities')->where('id', $city->id)->update([
                'slug' => Str::slug($city->name),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
