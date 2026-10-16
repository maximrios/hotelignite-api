<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación (entrega 1): planes para los dos lados, suscripciones, pagos,
 * comprobantes y el libro de créditos de IA. Misma base que el resto a
 * propósito: cobrar y habilitar tienen que poder pasar en una transacción.
 *
 * - `plans.audience` / `features.audience`: `accommodation` (hotelero) o `client`
 *   (municipio, cámara, agencia). Un solo catálogo, sin mezclar los selectores.
 * - `clients.plan_id`: el plan vigente, igual que `accommodations.plan_id`. Lo
 *   mantiene la suscripción; los permisos se siguen consultando con `hasFeature()`.
 * - `subscriptions`, `payments`, `invoices`, `ai_credit_transactions`: polimórficas
 *   sobre el que paga (`Accommodation` o `Client`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('audience', 20)->default('accommodation')->index();
            // Precio de maqueta, todavía no es lista comercial: la UI lo avisa.
            $table->boolean('price_provisional')->default(false);
        });

        Schema::table('features', function (Blueprint $table) {
            $table->string('audience', 20)->default('accommodation')->index();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->integer('plan_id')->nullable()->index();
            // Datos fiscales del comprador: van en el presupuesto y en la factura.
            $table->string('legal_name')->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->string('iva_condition', 40)->nullable();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subscriber');
            $table->integer('plan_id')->index();
            // trialing | pending | active | past_due | canceled
            $table->string('status', 20);
            $table->string('billing_period', 10);
            // Precio congelado al contratar: cambiar el plan no reprecia lo vendido.
            $table->bigInteger('price');
            $table->string('currency', 3)->default('ARS');
            // mercadopago | transfer | purchase_order
            $table->string('payment_method', 20)->nullable();
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            // Nº de orden de compra / expediente del municipio.
            $table->string('purchase_order_ref', 100)->nullable();
            $table->string('provider_subscription_id', 100)->nullable()->unique();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->morphs('payer');
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('ARS');
            // pending | approved | rejected | refunded
            $table->string('status', 20);
            // mercadopago | transfer
            $table->string('provider', 20);
            $table->string('provider_payment_id', 100)->nullable();
            $table->string('concept')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->jsonb('raw')->nullable();
            $table->timestamps();

            // Un webhook repetido no registra el mismo cobro dos veces.
            $table->unique(['provider', 'provider_payment_id']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->morphs('payer');
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            // A | B | C — a un municipio (exento) se le emite B.
            $table->string('type', 1)->nullable();
            $table->string('number', 30)->nullable();
            $table->string('concept');
            $table->bigInteger('amount');
            $table->string('currency', 3)->default('ARS');
            $table->date('issued_at');
            $table->string('cae', 20)->nullable();
            $table->string('pdf_url')->nullable();
            // issued | void
            $table->string('status', 10)->default('issued');
            $table->timestamps();
        });

        Schema::create('ai_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            // Con signo: + asignación/compra, − consumo.
            $table->integer('amount');
            // plan_grant | pack_purchase | usage | adjustment
            $table->string('reason', 20);
            // Período al que imputa (YYYY-MM): el consumo del mes se mide contra el plan.
            $table->string('period', 7)->index();
            $table->string('description')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Una sola suscripción viva por suscriptor; las canceladas quedan de historial.
            DB::statement("CREATE UNIQUE INDEX subscriptions_one_live_per_subscriber
                ON subscriptions (subscriber_type, subscriber_id)
                WHERE status IN ('trialing', 'pending', 'active', 'past_due')");

            // La asignación mensual del plan, una vez por período.
            DB::statement("CREATE UNIQUE INDEX ai_credit_one_grant_per_period
                ON ai_credit_transactions (owner_type, owner_id, period)
                WHERE reason = 'plan_grant'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_transactions');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['plan_id', 'legal_name', 'tax_id', 'iva_condition']);
        });

        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('audience');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['audience', 'price_provisional']);
        });
    }
};
