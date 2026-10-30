<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logo del client para la doble marca: mail de invitación, página donde
 * aterriza el hotelero (PMS) y barra del portal. Vive en Cloudinary — lo sube
 * el browser con firma, acá sólo la URL — porque el mail necesita PNG y
 * Cloudinary rasteriza un SVG con sólo cambiar la URL (`Client::logoUrl()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('logo_url', 500)->nullable()->after('email');
            $table->string('logo_public_id', 255)->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['logo_url', 'logo_public_id']);
        });
    }
};
