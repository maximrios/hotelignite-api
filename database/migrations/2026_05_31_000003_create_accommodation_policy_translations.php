<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accommodation_policy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')
                    ->constrained('accommodation_policies')
                    ->cascadeOnDelete();
            $table->foreignId('language_id')
                    ->constrained()
                    ->cascadeOnDelete();

            $table->text('house_rules')->nullable();

            $table->unique(['policy_id', 'language_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_policy_translations');
    }
};
