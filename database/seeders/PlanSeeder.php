<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Escalera comercial de tiers. Precios en centavos (ARS).
     * Idempotente: usa updateOrInsert por slug.
     */
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free',
                'name' => 'Free',
                'subtitle' => 'Tu hotel en el portal',
                'description' => 'Cargá tu alojamiento y armá tu web dentro del portal, sin costo.',
                'price' => 0,
                'currency' => 'ARS',
                'billing_period' => 'free',
                'trial_days' => 0,
                'sort_order' => 1,
                'is_public' => true,
                'enabled' => true,
            ],
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'subtitle' => 'Vendé directo',
                'description' => 'Motor de reservas propio con cobros online y gestión de tarifas.',
                'price' => 1500000,
                'currency' => 'ARS',
                'billing_period' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 2,
                'is_public' => true,
                'enabled' => true,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'subtitle' => 'Distribuí y operá',
                'description' => 'Channel manager multi-OTA + PMS para la operación diaria.',
                'price' => 3900000,
                'currency' => 'ARS',
                'billing_period' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 3,
                'is_public' => true,
                'enabled' => true,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'subtitle' => 'Todo + fiscal AR',
                'description' => 'Facturación ARCA, multipropiedad, acceso a la API y soporte dedicado.',
                'price' => 6900000,
                'currency' => 'ARS',
                'billing_period' => 'monthly',
                'trial_days' => 0,
                'sort_order' => 4,
                'is_public' => true,
                'enabled' => true,
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('plans')->updateOrInsert(
                ['slug' => $plan['slug']],
                array_merge($plan, ['updated_at' => now(), 'created_at' => now()])
            );
        }

        $this->migrateLegacyBasicPlan();
    }

    /**
     * El plan sembrado por la migración original ('basic', habilitaba reservas)
     * queda reemplazado por la nueva escalera. Reasigna los alojamientos que
     * estaban en 'basic' al tier 'starter' (primer tier con motor de reservas)
     * y elimina el plan obsoleto. Idempotente: si 'basic' ya no existe, no hace nada.
     */
    private function migrateLegacyBasicPlan(): void
    {
        $basicId   = DB::table('plans')->where('slug', 'basic')->value('id');
        $starterId = DB::table('plans')->where('slug', 'starter')->value('id');

        if (! $basicId || ! $starterId) {
            return;
        }

        DB::table('accommodations')->where('plan_id', $basicId)->update(['plan_id' => $starterId]);
        DB::table('accounts')->where('plan_id', $basicId)->update(['plan_id' => $starterId]);
        DB::table('plans')->where('id', $basicId)->delete();
    }
}
