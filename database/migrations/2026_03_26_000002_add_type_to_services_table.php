<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sin `->after('slug')`: la tabla `services` legacy (del dump) no tiene
     * columna `slug`, así que las nuevas se agregan al final.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'type')) {
                $table->enum('type', ['general', 'room', 'bathroom', 'accessibility', 'kitchen'])
                    ->default('general');
            }
            if (! Schema::hasColumn('services', 'is_highlighted')) {
                $table->boolean('is_highlighted')->default(false)->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_highlighted']);
        });
    }
};
