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
        // `array_merge` y no `+`: con `+` gana el array de la izquierda y los
        // límites que un plan superior sube (`users`, `channels_connected`) nunca
        // se aplicaban — todos los planes quedaban con los del Free.
        // Escalera acordada con hotelería (2026-10-28): el Free incluye la
        // operación diaria (calendario, reservas, huéspedes) con tope de
        // habitaciones — un free sin reservas no genera hábito ni datos.
        $free = [
            'listing' => null, 'microsite' => null, 'multilang' => null, 'tours' => null,
            'pms' => null, 'front_desk' => null,
            'rooms' => 10, 'accommodations' => 1, 'users' => 1, 'channels_connected' => 0,
        ];

        $starter = array_merge($free, [
            'booking_engine' => null, 'rate_management' => null,
            'promotions' => null, 'payment_gateway' => null,
            'housekeeping' => null,
            'rooms' => 25, 'users' => 3,
        ]);

        $pro = array_merge($starter, [
            // Límite del asistente = créditos de IA por mes.
            'ai_assistant' => 500, 'pms_reports' => null, 'cash_register' => null,
            'rooms' => 60, 'users' => 5,
        ]);

        $enterprise = array_merge($pro, [
            'channel_manager' => null, 'rate_parity' => null, 'ical_sync' => null,
            'invoicing_arca' => null, 'accounting_export' => null, 'api_access' => null,
            'ai_assistant' => 1000,
            'rooms' => null, 'accommodations' => null, 'users' => null, 'channels_connected' => 3,
        ]);

        $matrix = [
            'free' => $free,
            'starter' => $starter,
            'pro' => $pro,
            'enterprise' => $enterprise,
        ];

        $planIds = DB::table('plans')->pluck('id', 'slug');
        $featureIds = DB::table('features')->pluck('id', 'slug');

        foreach ($matrix as $planSlug => $features) {
            $planId = $planIds[$planSlug] ?? null;
            if (! $planId) {
                continue;
            }

            // Sincroniza: lo que el plan ya no incluye se quita.
            $keep = collect(array_keys($features))->map(fn ($slug) => $featureIds[$slug] ?? null)->filter()->all();
            DB::table('plan_feature')->where('plan_id', $planId)->whereNotIn('feature_id', $keep)->delete();

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
