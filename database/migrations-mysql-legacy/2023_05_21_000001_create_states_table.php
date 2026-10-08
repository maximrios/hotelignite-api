<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del esquema real de `states` (tabla legacy del dump, formalizada).
 * Idempotente: si la tabla ya existe (esta DB), es no-op. En una DB fresca la
 * crea tal cual está hoy (ids ya convertidos a bigint donde correspondía).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('states')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `states` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(30) NOT NULL,
  `country_id` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
