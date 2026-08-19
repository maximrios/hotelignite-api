<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            // Documento de identidad — obligatorio por ley argentina (Ley 18.829 / SECTUR)
            if (! Schema::hasColumn('guests', 'document_type')) {
                $table->enum('document_type', ['dni', 'passport', 'cuit', 'other'])
                    ->nullable()->after('last_name');
            }
            if (! Schema::hasColumn('guests', 'document_number')) {
                $table->string('document_number', 30)->nullable()->after('document_type');
            }

            // Datos personales
            if (! Schema::hasColumn('guests', 'nationality')) {
                $table->string('nationality', 3)->nullable()->after('document_number'); // ISO 3166-1 alpha-3
            }
            if (! Schema::hasColumn('guests', 'birthdate')) {
                $table->date('birthdate')->nullable()->after('nationality');
            }
        });

        // Índice para búsqueda en check-in (idempotente)
        $hasIndex = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'guests')
            ->where('index_name', 'guests_document_type_document_number_index')
            ->exists();

        if (! $hasIndex) {
            Schema::table('guests', fn (Blueprint $table) => $table->index(['document_type', 'document_number']));
        }
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex(['document_type', 'document_number']);
            $table->dropColumn(['document_type', 'document_number', 'nationality', 'birthdate']);
        });
    }
};
