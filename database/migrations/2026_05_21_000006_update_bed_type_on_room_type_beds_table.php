<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Normalizar valores existentes antes de aplicar el enum
        $map = [
            'king bed'   => 'king',
            'queen bed'  => 'queen',
            'double bed' => 'double',
            'twin bed'   => 'twin',
            'single bed' => 'single',
            'sofa bed'   => 'sofa_bed',
            'bunk bed'   => 'bunk_bed',
        ];

        foreach ($map as $old => $new) {
            DB::table('room_type_beds')
                ->whereRaw('LOWER(type) = ?', [$old])
                ->update(['type' => $new]);
        }

        // Valores no reconocidos → 'double' como fallback antes de aplicar enum
        $valid = ['king', 'queen', 'double', 'twin', 'single', 'sofa_bed', 'bunk_bed', 'crib'];
        DB::table('room_type_beds')
            ->whereNotIn('type', $valid)
            ->update(['type' => 'double']);

        DB::statement("ALTER TABLE room_type_beds MODIFY COLUMN type ENUM('king','queen','double','twin','single','sofa_bed','bunk_bed','crib') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE room_type_beds MODIFY COLUMN type VARCHAR(255) NOT NULL");
    }
};
