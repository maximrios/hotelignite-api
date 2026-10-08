<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot del esquema real de `bookings` (tabla legacy del dump, formalizada).
 * Idempotente: si la tabla ya existe (esta DB), es no-op. En una DB fresca la
 * crea tal cual está hoy (ids ya convertidos a bigint donde correspondía).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bookings')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE `bookings` (
  `id` varchar(255) NOT NULL,
  `accommodation_id` int DEFAULT NULL,
  `room_id` int DEFAULT NULL,
  `tour_id` int DEFAULT NULL,
  `checkin` date DEFAULT NULL,
  `checkout` date DEFAULT NULL,
  `adults` int DEFAULT NULL,
  `childrens` int DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
