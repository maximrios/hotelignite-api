<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Catálogo de tipos de documento del legajo. Idempotente: updateOrInsert por
     * slug. Hoy foco en `accommodation`; `entity_type` NULL = universal.
     * `owner_level`: `account` (de la empresa, ej. fiscal) o `entity` (por propiedad).
     */
    public function run(): void
    {
        $types = [
            ['slug' => 'habilitacion_municipal', 'name' => 'Habilitación municipal',            'entity_type' => 'accommodation', 'owner_level' => 'entity',  'sort_order' => 10],
            ['slug' => 'certificado_bomberos',   'name' => 'Certificado de bomberos',           'entity_type' => 'accommodation', 'owner_level' => 'entity',  'sort_order' => 20],
            ['slug' => 'seguro_rc',              'name' => 'Seguro de responsabilidad civil',   'entity_type' => 'accommodation', 'owner_level' => 'entity',  'sort_order' => 30],
            ['slug' => 'registro_hotelero',      'name' => 'Registro hotelero provincial',      'entity_type' => 'accommodation', 'owner_level' => 'entity',  'sort_order' => 40],
            ['slug' => 'inscripcion_afip',       'name' => 'Inscripción AFIP / ARCA',           'entity_type' => null,            'owner_level' => 'account', 'sort_order' => 50],
        ];

        foreach ($types as $type) {
            DB::table('document_types')->updateOrInsert(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'entity_type' => $type['entity_type'],
                    'owner_level' => $type['owner_level'],
                    'sort_order' => $type['sort_order'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}
