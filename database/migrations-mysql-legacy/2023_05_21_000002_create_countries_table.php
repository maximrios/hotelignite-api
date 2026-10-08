<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del esquema real de `countries` (tabla legacy del dump, formalizada).
 * Idempotente: si la tabla ya existe (esta DB), es no-op. En una DB fresca la
 * crea tal cual está hoy (ids ya convertidos a bigint donde correspondía).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('countries')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `countries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `iso_code` varchar(3) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
