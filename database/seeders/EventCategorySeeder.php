<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventCategorySeeder extends Seeder
{
    /**
     * Categorías de eventos, un nivel (docs/events-plan.md). `insertOrIgnore` por
     * slug: re-correrlo agrega lo que falte sin pisar lo editado. Íconos de lucide.
     */
    public function run(): void
    {
        $categories = [
            ['music', 'Música', 'music'],
            ['festival', 'Festivales y fiestas populares', 'party-popper'],
            ['food-drink', 'Gastronomía', 'utensils'],
            ['culture', 'Cultura y arte', 'palette'],
            ['sports', 'Deportes', 'trophy'],
            ['fair-congress', 'Ferias y congresos', 'presentation'],
            ['religious', 'Religiosos', 'church'],
            ['family', 'Familia y niños', 'baby'],
        ];

        $now = now();

        DB::table('event_categories')->insertOrIgnore(array_map(
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
