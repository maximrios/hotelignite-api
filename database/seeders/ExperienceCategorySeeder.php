<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExperienceCategorySeeder extends Seeder
{
    /**
     * Categorías de experiencias, un nivel (docs/experiences-plan.md).
     * `insertOrIgnore` por slug: re-correrlo agrega lo que falte sin pisar lo
     * editado. Íconos de lucide.
     */
    public function run(): void
    {
        $categories = [
            ['excursion', 'Excursiones', 'bus'],
            ['adventure', 'Aventura', 'mountain'],
            ['wine', 'Enoturismo', 'wine'],
            ['food-drink', 'Gastronomía', 'utensils'],
            ['culture', 'Cultura y comunidades', 'landmark'],
            ['nature', 'Naturaleza y avistaje', 'binoculars'],
            ['scenic', 'Trenes y rutas escénicas', 'train-front'],
            ['wellness', 'Bienestar', 'flower-2'],
            ['workshop', 'Talleres', 'hand'],
        ];

        $now = now();

        DB::table('experience_categories')->insertOrIgnore(array_map(
            fn (array $c, int $order) => [
                'slug' => $c[0],
                'name' => $c[1],
                'icon' => $c[2],
                'sort_order' => $order,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $categories,
            array_keys($categories),
        ));
    }
}
