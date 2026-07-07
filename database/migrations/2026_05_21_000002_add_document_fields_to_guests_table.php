<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            // Documento de identidad — obligatorio por ley argentina (Ley 18.829 / SECTUR)
            $table->enum('document_type', ['dni', 'passport', 'cuit', 'other'])
                  ->nullable()->after('last_name');
            $table->string('document_number', 30)->nullable()->after('document_type');

            // Datos personales
            $table->string('nationality', 3)->nullable()->after('document_number'); // ISO 3166-1 alpha-3
            $table->date('birthdate')->nullable()->after('nationality');

            // Índice para búsqueda en check-in
            $table->index(['document_type', 'document_number']);
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex(['document_type', 'document_number']);
            $table->dropColumn(['document_type', 'document_number', 'nationality', 'birthdate']);
        });
    }
};
