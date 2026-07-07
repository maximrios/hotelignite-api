<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeatureSeeder extends Seeder
{
    /**
     * Catálogo de funcionalidades del SaaS, agrupadas por módulo.
     * Idempotente: usa updateOrInsert por slug.
     */
    public function run(): void
    {
        $features = [
            // core — presencia y catálogo (base, va en el free)
            ['slug' => 'listing',            'name' => 'Alta de alojamiento',          'module' => 'core',         'type' => 'boolean'],
            ['slug' => 'microsite',          'name' => 'Web en el portal',             'module' => 'core',         'type' => 'boolean'],
            ['slug' => 'multilang',          'name' => 'Descripciones multilenguaje',  'module' => 'core',         'type' => 'boolean'],
            ['slug' => 'tours',              'name' => 'Publicación de tours',         'module' => 'core',         'type' => 'boolean'],

            // booking — comercialización directa
            ['slug' => 'booking_engine',     'name' => 'Motor de reservas',            'module' => 'booking',      'type' => 'boolean'],
            ['slug' => 'rate_management',    'name' => 'Gestión de tarifas',           'module' => 'booking',      'type' => 'boolean'],
            ['slug' => 'promotions',         'name' => 'Promociones y cupones',        'module' => 'booking',      'type' => 'boolean'],
            ['slug' => 'payment_gateway',    'name' => 'Pasarela de pagos',            'module' => 'booking',      'type' => 'boolean'],

            // distribution — channel manager
            ['slug' => 'channel_manager',    'name' => 'Channel Manager',              'module' => 'distribution', 'type' => 'boolean'],
            ['slug' => 'rate_parity',        'name' => 'Control de paridad',           'module' => 'distribution', 'type' => 'boolean'],
            ['slug' => 'ical_sync',          'name' => 'Sincronización iCal',          'module' => 'distribution', 'type' => 'boolean'],

            // operations — PMS
            ['slug' => 'pms',                'name' => 'PMS',                          'module' => 'operations',   'type' => 'boolean'],
            ['slug' => 'front_desk',         'name' => 'Recepción / check-in',         'module' => 'operations',   'type' => 'boolean'],
            ['slug' => 'pms_reports',        'name' => 'Reportes (ADR / RevPAR)',      'module' => 'operations',   'type' => 'boolean'],
            ['slug' => 'cash_register',      'name' => 'Caja y cobros',                'module' => 'operations',   'type' => 'boolean'],

            // billing — facturación y fiscal (AR)
            ['slug' => 'invoicing_arca',     'name' => 'Facturación ARCA',             'module' => 'billing',      'type' => 'boolean'],
            ['slug' => 'accounting_export',  'name' => 'Exportación contable',         'module' => 'billing',      'type' => 'boolean'],

            // platform — transversales (escalan por tier)
            ['slug' => 'users',              'name' => 'Usuarios del staff',           'module' => 'platform',     'type' => 'limit'],
            ['slug' => 'accommodations',     'name' => 'Propiedades por cuenta',       'module' => 'platform',     'type' => 'limit'],
            ['slug' => 'channels_connected', 'name' => 'Canales conectados',           'module' => 'platform',     'type' => 'limit'],
            ['slug' => 'api_access',         'name' => 'Acceso a la API / webhooks',   'module' => 'platform',     'type' => 'boolean'],
        ];

        foreach ($features as $feature) {
            DB::table('features')->updateOrInsert(
                ['slug' => $feature['slug']],
                array_merge($feature, ['updated_at' => now(), 'created_at' => now()])
            );
        }
    }
}
