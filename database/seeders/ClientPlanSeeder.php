<?php

namespace Database\Seeders;

use App\Models\Client;
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
 *
 * Todo client tiene siempre un plan: el free de su tipo es el piso
 * (`Client::FREE_PLAN_SLUGS`). Al final se les asigna a los que no tienen.
 * `client_types` dice a quién se ofrece cada plan.
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
            ['slug' => 'experiences_published', 'name' => 'Experiencias publicadas',      'module' => 'content',  'type' => 'limit'],
            // `client_users` y no `users`: el slug es único entre audiencias y
            // `users` ya es la feature de los planes de hoteleros.
            ['slug' => 'client_users',         'name' => 'Usuarios del portal',           'module' => 'platform', 'type' => 'limit'],
        ];

        foreach ($features as $feature) {
            DB::table('features')->updateOrInsert(
                ['slug' => $feature['slug']],
                $feature + ['audience' => Plan::AUDIENCE_CLIENT, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        // Precios en centavos. Gobierno: anual. Agencia: mensual. Free: `free`.
        $plans = [
            'client-padron-inicial' => [
                'name' => 'Padrón inicial',
                'subtitle' => 'Para empezar sin expediente',
                'description' => 'Tu padrón en HotelIgnite e invitaciones ilimitadas. Publicá hasta 10 asociados en tu web.',
                'price' => 0,
                'billing_period' => 'free',
                'sort_order' => 100,
                'client_types' => [Client::TYPE_GOVERNMENT, Client::TYPE_CHAMBER],
                'features' => [
                    'associates_published' => 10, 'publication_widget' => null,
                    'api_requests_month' => 1_000, 'client_users' => 1,
                ],
            ],
            'client-padron' => [
                'name' => 'Padrón',
                'subtitle' => 'Municipios chicos y cámaras',
                'description' => 'Padrón de asociados publicado en tu web, con widget y API.',
                'price' => 120_000_000,
                'billing_period' => 'yearly',
                'sort_order' => 101,
                'client_types' => [Client::TYPE_GOVERNMENT, Client::TYPE_CHAMBER],
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
                'client_types' => [Client::TYPE_GOVERNMENT, Client::TYPE_CHAMBER],
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
                'client_types' => [Client::TYPE_GOVERNMENT, Client::TYPE_CHAMBER],
                'features' => [
                    'associates_published' => null, 'publication_widget' => null, 'publication_api' => null,
                    'api_requests_month' => null, 'ai_credits_month' => 10_000, 'tourism_content' => null,
                    'destination_stats' => null, 'official_registry' => null,
                ],
            ],
            'client-agencia-free' => [
                'name' => 'Agencia Free',
                'subtitle' => 'Para registrarte y empezar',
                'description' => 'Tu perfil de agencia, tu padrón de alojamientos y hasta 3 experiencias publicadas.',
                'price' => 0,
                'billing_period' => 'free',
                'sort_order' => 109,
                'client_types' => [Client::TYPE_AGENCY, Client::TYPE_COMPANY],
                'features' => [
                    'experiences_published' => 3, 'client_users' => 1, 'ai_credit_packs' => null,
                ],
            ],
            'client-agencia-pro' => [
                'name' => 'Agencia Pro',
                'subtitle' => 'Agencias que arman propuestas',
                'description' => 'Hasta 30 experiencias, widget para tu web, asistente IA y más usuarios.',
                'price' => 2_500_000,
                'billing_period' => 'monthly',
                'sort_order' => 110,
                'client_types' => [Client::TYPE_AGENCY, Client::TYPE_COMPANY],
                'features' => [
                    'experiences_published' => 30, 'client_users' => 3, 'publication_widget' => null,
                    'api_requests_month' => 10_000, 'ai_credits_month' => 1_000, 'ai_credit_packs' => null,
                ],
            ],
            // Slug histórico: era "Agencia" a secas, el único plan de agencias.
            'client-agencia' => [
                'name' => 'Agencia Venta',
                'subtitle' => 'Agencias que venden alojamiento',
                'description' => 'Experiencias ilimitadas, inventario por API para vender y asistente IA con más créditos.',
                'price' => 6_000_000,
                'billing_period' => 'monthly',
                'sort_order' => 111,
                'client_types' => [Client::TYPE_AGENCY, Client::TYPE_COMPANY],
                'features' => [
                    'associates_published' => 100, 'experiences_published' => null, 'client_users' => 10,
                    'publication_widget' => null, 'publication_api' => null,
                    'api_requests_month' => 50_000, 'ai_credits_month' => 5_000, 'ai_credit_packs' => null,
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
                'client_types' => json_encode($plan['client_types']),
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

        // Todo client tiene plan: a los que no tienen, el free de su tipo.
        foreach (Client::FREE_PLAN_SLUGS as $type => $slug) {
            DB::table('clients')
                ->whereNull('plan_id')
                ->where('type', $type)
                ->update(['plan_id' => DB::table('plans')->where('slug', $slug)->value('id')]);
        }
    }
}
