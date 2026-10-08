<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del esquema real de `channels` (tabla legacy del dump, formalizada).
 * Idempotente: si la tabla ya existe (esta DB), es no-op. En una DB fresca la
 * crea tal cual está hoy (ids ya convertidos a bigint donde correspondía).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channels')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `channels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_spanish_ci NOT NULL DEFAULT '0',
  `business_type` enum('direct','ota','agency','gds','corporate','tour_operator','metasearch') COLLATE utf8mb3_spanish_ci NOT NULL DEFAULT 'direct',
  `connection_type` enum('manual','api','ical','gds','email') COLLATE utf8mb3_spanish_ci NOT NULL DEFAULT 'manual',
  `code` varchar(20) COLLATE utf8mb3_spanish_ci DEFAULT NULL,
  `first_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_spanish_ci NOT NULL DEFAULT '0',
  `last_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_spanish_ci NOT NULL DEFAULT '0',
  `phone` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_spanish_ci NOT NULL DEFAULT '0',
  `email` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_spanish_ci NOT NULL DEFAULT '0',
  `web` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_spanish_ci NOT NULL DEFAULT '0',
  `commission_rate` decimal(5,2) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `feedback` tinyint NOT NULL DEFAULT '0',
  `ota` tinyint NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `channels_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_spanish_ci
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
