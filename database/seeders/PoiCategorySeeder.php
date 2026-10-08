<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PoiCategorySeeder extends Seeder
{
    /**
     * Taxonomía de puntos de interés, dos niveles (docs/points-of-interest-plan.md).
     * Idempotente y conservador: `insertOrIgnore` por slug. La taxonomía la edita
     * el staff desde el CRM, así que re-correr el seeder agrega lo que falte pero
     * no pisa nombres, íconos ni radios ya ajustados. Íconos: nombres de lucide.
     *
     * El radio va en la raíz; una hoja con `nearest_limit` (aeropuerto) se busca
     * por cercanía sin radio.
     */
    public function run(): void
    {
        $taxonomy = [
            ['food-drink', 'Gastronomía', 'utensils', 1000, [
                ['restaurant', 'Restaurante', 'utensils-crossed'],
                ['grill', 'Parrilla', 'beef'],
                ['cafe', 'Café y confitería', 'coffee'],
                ['bar', 'Bar', 'wine'],
                ['bakery-ice-cream', 'Panadería y heladería', 'ice-cream-cone'],
                ['winery', 'Bodega y vinoteca', 'grape'],
            ]],
            ['attractions', 'Atracciones y cultura', 'landmark', 3000, [
                ['landmark', 'Edificio emblemático', 'building-2'],
                ['museum', 'Museo', 'library'],
                ['church', 'Iglesia y templo', 'church'],
                ['square-park', 'Plaza y parque urbano', 'trees'],
                ['viewpoint', 'Mirador', 'binoculars'],
                ['historic-site', 'Sitio histórico', 'castle'],
                ['theater', 'Teatro y centro cultural', 'drama'],
            ]],
            ['nature', 'Naturaleza y aire libre', 'mountain', 30000, [
                ['nature-reserve', 'Área natural', 'tree-pine'],
                ['water-feature', 'Río, lago y cascada', 'waves'],
                ['trail', 'Sendero y circuito', 'footprints'],
                ['adventure', 'Aventura', 'tent'],
            ]],
            ['shopping', 'Compras', 'shopping-bag', 2000, [
                ['mall', 'Centro comercial', 'shopping-bag'],
                ['market', 'Feria y mercado', 'store'],
                ['supermarket', 'Supermercado', 'shopping-cart'],
                ['local-crafts', 'Artesanías y regionales', 'gift'],
            ]],
            ['entertainment', 'Entretenimiento', 'party-popper', 2000, [
                ['folk-show', 'Peña y espectáculo folklórico', 'music'],
                ['nightclub', 'Boliche', 'disc-3'],
                ['casino', 'Casino', 'dices'],
                ['cinema', 'Cine', 'clapperboard'],
                ['venue', 'Estadio y eventos', 'ticket'],
            ]],
            ['transport', 'Transporte', 'bus', 5000, [
                ['airport', 'Aeropuerto', 'plane', 3],
                ['bus-station', 'Terminal de ómnibus', 'bus'],
                ['train-station', 'Estación de tren', 'train-front'],
                ['car-rental', 'Alquiler de autos', 'car'],
            ]],
            ['health', 'Salud', 'heart-pulse', 3000, [
                ['hospital', 'Hospital y clínica', 'hospital'],
                ['pharmacy', 'Farmacia', 'pill'],
                ['emergency', 'Guardia', 'siren'],
            ]],
            ['services', 'Servicios', 'concierge-bell', 1000, [
                ['bank-atm', 'Banco y cajero', 'banknote'],
                ['currency-exchange', 'Casa de cambio', 'arrow-left-right'],
                ['tourist-office', 'Oficina de turismo', 'info'],
                ['gas-station', 'Estación de servicio', 'fuel'],
            ]],
        ];

        $now = now();

        foreach ($taxonomy as $rootOrder => [$slug, $name, $icon, $radius, $children]) {
            DB::table('poi_categories')->insertOrIgnore([[
                'slug' => $slug,
                'parent_id' => null,
                'name' => $name,
                'icon' => $icon,
                'sort_order' => $rootOrder,
                'default_radius_m' => $radius,
                'nearest_limit' => null,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]]);

            $parentId = DB::table('poi_categories')->where('slug', $slug)->value('id');

            $rows = [];
            foreach ($children as $order => $child) {
                $rows[] = [
                    'slug' => $child[0],
                    'parent_id' => $parentId,
                    'name' => $child[1],
                    'icon' => $child[2],
                    'sort_order' => $order,
                    'default_radius_m' => null,
                    'nearest_limit' => $child[3] ?? null,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('poi_categories')->insertOrIgnore($rows);
        }
    }
}
