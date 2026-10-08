<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de una consulta: `new` al recibirla (default) → `answered` cuando el
 * hotelero la responde. La UI del PMS lo usa para resaltar las pendientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inquiries', 'status')) {
            return;
        }

        Schema::table('inquiries', function (Blueprint $table) {
            $table->enum('status', ['new', 'answered'])->default('new')->after('message');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('inquiries', 'status')) {
            return;
        }

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
