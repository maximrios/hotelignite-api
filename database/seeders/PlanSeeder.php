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
        // Escalera acordada con hotelería (2026-10-28). Los slugs se mantienen
        // (otros sistemas los referencian); cambian nombre, precio y contenido.
        // Premium queda "Próximamente" hasta que exista el channel manager.
        $plans = [
            [
                'slug' => 'free',
                'name' => 'Free',
                'subtitle' => 'Inicio',
                'description' => 'Tu hotel en el portal y la operación diaria: calendario, reservas y huéspedes, hasta 10 habitaciones.',
                'price' => 0,
                'currency' => 'ARS',
                'billing_period' => 'free',
                'trial_days' => 0,
                'sort_order' => 1,
                'is_public' => true,
                'enabled' => true,
                'coming_soon' => false,
            ],
            [
                'slug' => 'starter',
                'name' => 'Básico',
                'subtitle' => 'Operación',
                'description' => 'Todo el Free más tarifas completas, housekeeping y motor de reservas, hasta 25 habitaciones.',
                'price' => 2500000,
                'currency' => 'ARS',
                'billing_period' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 2,
                'is_public' => true,
                'enabled' => true,
                'coming_soon' => false,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'subtitle' => 'Pro IA',
                'description' => 'Todo el Básico más el asistente con IA, reportes de ocupación y revenue, hasta 60 habitaciones.',
                'price' => 5500000,
                'currency' => 'ARS',
                'billing_period' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 3,
                'is_public' => true,
                'enabled' => true,
                'coming_soon' => false,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Premium',
                'subtitle' => 'Distribución',
                'description' => 'Todo el Pro más channel manager, habitaciones y usuarios ilimitados, API y facturación ARCA.',
                'price' => 9900000,
                'currency' => 'ARS',
                'billing_period' => 'monthly',
                'trial_days' => 0,
                'sort_order' => 4,
                'is_public' => true,
                'enabled' => true,
                'coming_soon' => true,
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
        $basicId = DB::table('plans')->where('slug', 'basic')->value('id');
        $starterId = DB::table('plans')->where('slug', 'starter')->value('id');

        if (! $basicId || ! $starterId) {
            return;
        }

        DB::table('accommodations')->where('plan_id', $basicId)->update(['plan_id' => $starterId]);
        DB::table('accounts')->where('plan_id', $basicId)->update(['plan_id' => $starterId]);
        DB::table('plans')->where('id', $basicId)->delete();
    }
}
