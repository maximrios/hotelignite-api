<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambio de plan pagado con Mercado Pago (suscripción preapproval sin plan,
 * estado `pending`): el pedido guarda la suscripción creada en Mercado Pago y
 * su link de pago; el webhook o la vuelta del hotelero lo aprueban.
 *
 * - `payment_method`: `mercadopago` o null (pedido manual que confirma el equipo).
 * - `external_reference`: ancla de conciliación que viaja a Mercado Pago.
 * - `provider_subscription_id`: id del preapproval; al aprobarse pasa a la
 *   suscripción (`subscriptions.provider_subscription_id`).
 * - `checkout_url`: `init_point`, para retomar el pago si el hotelero se fue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_change_requests', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable();
            $table->string('external_reference', 64)->nullable()->unique();
            $table->string('provider_subscription_id', 64)->nullable()->index();
            $table->text('checkout_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plan_change_requests', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'external_reference', 'provider_subscription_id', 'checkout_url']);
        });
    }
};
