<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del esquema real de `guests` (tabla legacy del dump, formalizada).
 * Idempotente: si la tabla ya existe (esta DB), es no-op. En una DB fresca la
 * crea tal cual está hoy (ids ya convertidos a bigint donde correspondía).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('guests')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `guests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL DEFAULT '0',
  `last_name` varchar(255) NOT NULL DEFAULT '0',
  `document_type` enum('dni','passport','cuit','other') DEFAULT NULL,
  `document_number` varchar(30) DEFAULT NULL,
  `nationality` varchar(3) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `identity_number` varchar(50) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `email` varchar(255) NOT NULL DEFAULT '0',
  `address` varchar(255) NOT NULL DEFAULT '0',
  `phone` varchar(255) NOT NULL DEFAULT '0',
  `gender` char(50) NOT NULL DEFAULT '0',
  `country_id` varchar(50) NOT NULL DEFAULT '0',
  `state_id` int NOT NULL DEFAULT '0',
  `language_id` varchar(50) NOT NULL DEFAULT '0',
  `user_created` int DEFAULT NULL,
  `created` datetime DEFAULT NULL,
  `user_modified` int DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `enabled` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `guests_document_type_document_number_index` (`document_type`,`document_number`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
