<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `language_id` es el código de idioma ('es', 'en'), igual que en
     * `accommodation_descriptions` — `languages.id` es `char` en la BD legacy,
     * no un entero, así que no admite `foreignId()`.
     */
    public function up(): void
    {
        if (Schema::hasTable('accommodation_policy_translations')) {
            return;
        }

        Schema::create('accommodation_policy_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')
                    ->constrained('accommodation_policies')
                    ->cascadeOnDelete();
            $table->string('language_id', 10)->default('es');

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
