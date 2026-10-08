<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del esquema real de `accommodation_services` (tabla legacy del dump, formalizada).
 * Idempotente: si la tabla ya existe (esta DB), es no-op. En una DB fresca la
 * crea tal cual está hoy (ids ya convertidos a bigint donde correspondía).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accommodation_services')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `accommodation_services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `accommodation_id` int NOT NULL DEFAULT '0',
  `service_id` int NOT NULL DEFAULT '0',
  `enabled` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_services');
    }
};
