<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->enum('type', ['general', 'room', 'bathroom', 'accessibility', 'kitchen'])
                  ->default('general')
                  ->after('slug');
            $table->boolean('is_highlighted')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_highlighted']);
        });
    }
};
