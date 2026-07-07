<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanFeatureSeeder extends Seeder
{
    /**
     * Matriz plan ↔ feature. Los tiers son acumulativos: cada uno hereda
     * las features del anterior. El valor es el límite (null = ilimitado /
     * no aplica para features boolean). Idempotente.
     */
    public function run(): void
    {
        // Bloques de features por nivel. Cada plan suma su bloque + los previos.
        $free = [
            'listing' => null, 'microsite' => null, 'multilang' => null, 'tours' => null,
            'accommodations' => 1, 'users' => 1, 'channels_connected' => 0,
        ];

        $starter = $free + [
            'booking_engine' => null, 'rate_management' => null,
            'promotions' => null, 'payment_gateway' => null,
            'users' => 3,
        ];

        $pro = $starter + [
            'channel_manager' => null, 'rate_parity' => null, 'ical_sync' => null,
            'pms' => null, 'front_desk' => null, 'pms_reports' => null, 'cash_register' => null,
            'users' => 10, 'channels_connected' => 10,
        ];

        $enterprise = $pro + [
            'invoicing_arca' => null, 'accounting_export' => null, 'api_access' => null,
            'accommodations' => null, 'users' => null, 'channels_connected' => null,
        ];

        $matrix = [
            'free'       => $free,
            'starter'    => $starter,
            'pro'        => $pro,
            'enterprise' => $enterprise,
        ];

        $planIds    = DB::table('plans')->pluck('id', 'slug');
        $featureIds = DB::table('features')->pluck('id', 'slug');

        foreach ($matrix as $planSlug => $features) {
            $planId = $planIds[$planSlug] ?? null;
            if (! $planId) {
                continue;
            }

            foreach ($features as $featureSlug => $limit) {
                $featureId = $featureIds[$featureSlug] ?? null;
                if (! $featureId) {
                    continue;
                }

                DB::table('plan_feature')->updateOrInsert(
                    ['plan_id' => $planId, 'feature_id' => $featureId],
                    ['limit' => $limit, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }
}
