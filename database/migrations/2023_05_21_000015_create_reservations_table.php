<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del esquema real de `reservations` (tabla legacy del dump, formalizada).
 * Idempotente: si la tabla ya existe (esta DB), es no-op. En una DB fresca la
 * crea tal cual está hoy (ids ya convertidos a bigint donde correspondía).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `reservations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `source_reference` varchar(255) DEFAULT NULL,
  `confirmation_number` varchar(255) DEFAULT NULL,
  `guest_id` int NOT NULL,
  `accommodation_id` int NOT NULL,
  `channel_id` int DEFAULT NULL,
  `currency_id` char(4) NOT NULL,
  `language_id` char(4) NOT NULL,
  `package_id` int NOT NULL,
  `regimen_id` char(4) DEFAULT NULL,
  `additional_id` int DEFAULT NULL,
  `discount_id` int DEFAULT NULL,
  `cupon_id` int DEFAULT NULL,
  `country_id` char(50) DEFAULT NULL,
  `status_id` tinyint NOT NULL,
  `payment_method_id` int NOT NULL,
  `organization_id` int DEFAULT NULL,
  `arrival` date NOT NULL,
  `departure` date NOT NULL,
  `nights` int DEFAULT NULL,
  `rate` decimal(10,2) NOT NULL DEFAULT '0.00',
  `rate_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `adults` int DEFAULT NULL,
  `childrens` int DEFAULT NULL,
  `arrival_hour` char(50) DEFAULT NULL,
  `departure_hour` char(50) DEFAULT NULL,
  `checkin` datetime DEFAULT NULL,
  `checkout` datetime DEFAULT NULL,
  `reservation_type` tinyint NOT NULL,
  `user_created` tinyint DEFAULT NULL,
  `created` datetime NOT NULL,
  `user_modified` tinyint DEFAULT NULL,
  `modified` datetime NOT NULL,
  `comment` varchar(255) NOT NULL DEFAULT '0000-00-00 00:00:00',
  `voucher` varchar(255) DEFAULT NULL,
  `token` varchar(255) DEFAULT NULL,
  `enabled` tinyint DEFAULT NULL,
  `wubook_code` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `channel_code` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `room_id` bigint unsigned DEFAULT NULL,
  `extra_beds` tinyint unsigned NOT NULL DEFAULT '0',
  `children` tinyint unsigned NOT NULL DEFAULT '0',
  `checkout_time` time DEFAULT NULL,
  `checkin_time` time DEFAULT NULL,
  `checkout_date` date DEFAULT NULL,
  `checkin_date` date DEFAULT NULL,
  `rate_plan_id` bigint unsigned DEFAULT NULL,
  `rate_amount` int unsigned DEFAULT NULL,
  `total_amount` int unsigned DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'ARS',
  `commission_amount` int unsigned DEFAULT NULL,
  `guarantee_type` enum('credit_card','deposit','agency_voucher','none') NOT NULL DEFAULT 'none',
  `deposit_amount` int unsigned DEFAULT NULL,
  `deposit_date` date DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` varchar(255) DEFAULT NULL,
  `special_requests` text,
  `internal_notes` text,
  PRIMARY KEY (`id`),
  KEY `reservations_room_id_foreign` (`room_id`),
  KEY `reservations_checkin_date_index` (`checkin_date`),
  KEY `reservations_checkout_date_index` (`checkout_date`),
  KEY `reservations_status_id_index` (`status_id`),
  CONSTRAINT `reservations_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
