<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del esquema real de `services` (tabla legacy del dump, formalizada).
 * Idempotente: si la tabla ya existe (esta DB), es no-op. En una DB fresca la
 * crea tal cual está hoy (ids ya convertidos a bigint donde correspondía).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('services')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '0',
  `slug` varchar(255) DEFAULT NULL,
  `type` enum('general','room','bathroom','accessibility','kitchen') NOT NULL DEFAULT 'general',
  `is_highlighted` tinyint(1) NOT NULL DEFAULT '0',
  `ico` varchar(255) NOT NULL DEFAULT '0',
  `enabled` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
