<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del esquema real de `accommodations` (tabla legacy del dump, formalizada).
 * Idempotente: si la tabla ya existe (esta DB), es no-op. En una DB fresca la
 * crea tal cual está hoy (ids ya convertidos a bigint donde correspondía).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accommodations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `accommodations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `file_number` varchar(50) DEFAULT NULL,
  `tax_identification` varchar(50) NOT NULL DEFAULT '0',
  `logo` varchar(255) NOT NULL DEFAULT '0',
  `account_id` int NOT NULL DEFAULT '0',
  `name` varchar(255) NOT NULL DEFAULT '0',
  `address` varchar(255) NOT NULL DEFAULT '0',
  `email` varchar(255) NOT NULL DEFAULT '0',
  `phone` varchar(255) NOT NULL DEFAULT '0',
  `email_reservations` varchar(255) NOT NULL DEFAULT '0',
  `phone_reservations` varchar(255) NOT NULL DEFAULT '0',
  `web` varchar(255) NOT NULL DEFAULT '0',
  `latitude` varchar(255) NOT NULL DEFAULT '0',
  `longitude` varchar(255) NOT NULL DEFAULT '0',
  `postal_code` varchar(50) NOT NULL DEFAULT '0',
  `bank_data` text,
  `city_id` bigint unsigned NOT NULL,
  `state_id` int NOT NULL DEFAULT '0',
  `country_id` char(50) NOT NULL DEFAULT '0',
  `type_id` int NOT NULL DEFAULT '0',
  `language_id` char(4) NOT NULL DEFAULT '0',
  `currency_id` char(4) NOT NULL DEFAULT '0',
  `token` varchar(100) NOT NULL DEFAULT '0',
  `comment` text,
  `slug` varchar(255) DEFAULT NULL,
  `active` tinyint DEFAULT NULL,
  `test` tinyint DEFAULT NULL,
  `plan_id` bigint unsigned DEFAULT NULL,
  `expiration` date DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `enabled` tinyint NOT NULL DEFAULT '1',
  `channel_code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodations');
    }
};
