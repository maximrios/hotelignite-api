<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Una foto por extra (Cloudinary): la tarjeta del motor la muestra en lugar del ícono. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extras', function (Blueprint $table) {
            $table->string('image_url', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('extras', fn (Blueprint $table) => $table->dropColumn('image_url'));
    }
};
