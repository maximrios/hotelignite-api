<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Planes y features de los clients B2B (municipios, cámaras, agencias).
 *
 * **Los precios son provisorios** (`price_provisional = true`): para un
 * municipio el precio se calibra contra el valor del jornal y el tope de
 * adjudicación simple vigentes, que cambian por decreto. Por eso viven en la
 * base y no en el código. Re-correr el seeder **no pisa precios ya editados**:
 * sólo los escribe al crear el plan.
 *
 * Los planes de gobierno son anuales y con tope duro (sin `ai_credit_packs`):
 * un municipio no puede pagar un excedente que no tiene presupuestado.
 */
class ClientPlanSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            ['slug' => 'associates_published', 'name' => 'Asociados publicados',          'module' => 'padron',   'type' => 'limit'],
            ['slug' => 'publication_widget',   'name' => 'Widget de publicación',         'module' => 'padron',   'type' => 'boolean'],
            ['slug' => 'publication_api',      'name' => 'API de publicación',            'module' => 'padron',   'type' => 'boolean'],
            ['slug' => 'api_requests_month',   'name' => 'Pedidos a la API por mes',      'module' => 'platform', 'type' => 'limit'],
            ['slug' => 'ai_credits_month',     'name' => 'Créditos de IA por mes',        'module' => 'ai',       'type' => 'limit'],
            ['slug' => 'ai_credit_packs',      'name' => 'Compra de packs de créditos',   'module' => 'ai',       'type' => 'boolean'],
            ['slug' => 'tourism_content',      'name' => 'Eventos y lugares de interés',  'module' => 'content',  'type' => 'boolean'],
            ['slug' => 'destination_stats',    'name' => 'Estadísticas del destino',      'module' => 'content',  'type' => 'boolean'],
            ['slug' => 'official_registry',    'name' => 'Padrón oficial (documentación)', 'module' => 'padron',  'type' => 'boolean'],
            ['slug' => 'sales',                'name' => 'Venta de alojamientos',         'module' => 'sales',    'type' => 'boolean'],
        ];

        foreach ($features as $feature) {
            DB::table('features')->updateOrInsert(
                ['slug' => $feature['slug']],
                $feature + ['audience' => Plan::AUDIENCE_CLIENT, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // Precios en centavos. Gobierno: anual. Agencia: mensual.
        $plans = [
            'client-padron' => [
                'name' => 'Padrón',
                'subtitle' => 'Municipios chicos y cámaras',
                'description' => 'Padrón de asociados publicado en tu web, con widget y API.',
                'price' => 120_000_000,
                'billing_period' => 'yearly',
                'sort_order' => 101,
                'features' => [
                    'associates_published' => 50, 'publication_widget' => null, 'publication_api' => null,
                    'api_requests_month' => 10_000, 'ai_credits_month' => 500, 'tourism_content' => null,
                ],
            ],
            'client-destino' => [
                'name' => 'Destino',
                'subtitle' => 'Municipios turísticos',
                'description' => 'Todo el padrón del destino, contenido turístico y estadísticas.',
                'price' => 300_000_000,
                'billing_period' => 'yearly',
                'sort_order' => 102,
                'features' => [
                    'associates_published' => 300, 'publication_widget' => null, 'publication_api' => null,
                    'api_requests_month' => 50_000, 'ai_credits_month' => 2_000, 'tourism_content' => null,
                    'destination_stats' => null,
                ],
            ],
            'client-provincia' => [
                'name' => 'Provincia',
                'subtitle' => 'Entes provinciales y regiones',
                'description' => 'Padrón ilimitado, padrón oficial con documentación y estadísticas.',
                'price' => 720_000_000,
                'billing_period' => 'yearly',
                'sort_order' => 103,
                'features' => [
                    'associates_published' => null, 'publication_widget' => null, 'publication_api' => null,
                    'api_requests_month' => null, 'ai_credits_month' => 10_000, 'tourism_content' => null,
                    'destination_stats' => null, 'official_registry' => null,
                ],
            ],
            'client-agencia' => [
                'name' => 'Agencia',
                'subtitle' => 'Agencias que venden',
                'description' => 'Inventario por API para vender, con asistente IA y packs de créditos.',
                'price' => 4_500_000,
                'billing_period' => 'monthly',
                'sort_order' => 110,
                'features' => [
                    'associates_published' => 100, 'publication_api' => null,
                    'api_requests_month' => 50_000, 'ai_credits_month' => 2_000, 'ai_credit_packs' => null,
                    'sales' => null,
                ],
            ],
        ];

        $featureIds = DB::table('features')->where('audience', Plan::AUDIENCE_CLIENT)->pluck('id', 'slug');

        foreach ($plans as $slug => $plan) {
            $attributes = [
                'name' => $plan['name'],
                'subtitle' => $plan['subtitle'],
                'description' => $plan['description'],
                'billing_period' => $plan['billing_period'],
                'sort_order' => $plan['sort_order'],
                'audience' => Plan::AUDIENCE_CLIENT,
                'currency' => 'ARS',
                'trial_days' => 0,
                'is_public' => true,
                'updated_at' => now(),
            ];

            $existing = DB::table('plans')->where('slug', $slug)->value('id');
            if ($existing) {
                DB::table('plans')->where('id', $existing)->update($attributes);
                $planId = $existing;
            } else {
                $planId = DB::table('plans')->insertGetId($attributes + [
                    'slug' => $slug,
                    'price' => $plan['price'],
                    'price_provisional' => true,
                    'enabled' => true,
                    'created_at' => now(),
                ]);
            }

            foreach ($plan['features'] as $featureSlug => $limit) {
                DB::table('plan_feature')->updateOrInsert(
                    ['plan_id' => $planId, 'feature_id' => $featureIds[$featureSlug]],
                    ['limit' => $limit, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            // Features que el plan ya no incluye (si se achica la matriz).
            DB::table('plan_feature')
                ->where('plan_id', $planId)
                ->whereNotIn('feature_id', collect($plan['features'])->keys()->map(fn (string $f) => $featureIds[$f]))
                ->delete();
        }
    }
}
