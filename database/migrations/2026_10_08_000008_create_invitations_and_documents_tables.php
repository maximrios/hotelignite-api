<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base para PostgreSQL — 8/9: invitaciones, vínculo alojamiento ↔
 * client y documentación (tipos, archivos, a quién se compartieron y qué exige
 * cada client).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Ver `.claude/skills/invitations.md`. `client_id` null = invita el staff;
        // `accommodation_id` sólo apunta a algo en los casos B y C.
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('accommodation_id')->nullable()->index();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Columnas generadas: valen NULL salvo con la invitación pendiente, y
            // como cada NULL es distinto en un índice único, los índices de abajo
            // sólo restringen a las pendientes. Postgres soporta índices parciales
            // (`... WHERE`), pero se mantienen así para no tocar `Invitation` ni el
            // comportamiento que ya cubren los tests.
            $table->string('pending_email')->nullable()
                ->storedAs('CASE WHEN accepted_at IS NULL AND declined_at IS NULL THEN email END');
            $table->unsignedBigInteger('pending_accommodation_id')->nullable()
                ->storedAs('CASE WHEN accepted_at IS NULL AND declined_at IS NULL THEN accommodation_id END');

            $table->string('contact_name')->nullable();
            $table->string('accommodation_name')->nullable();

            $table->index(['client_id', 'email']);
            $table->unique(['client_id', 'pending_email'], 'invitations_pending_email_unique');
            $table->unique(['client_id', 'pending_accommodation_id'], 'invitations_pending_accommodation_unique');
        });

        // Qué alojamientos ve un client. Lo consumen `Accommodation::scopeVisibleTo()`
        // y `AccommodationPolicy`: es la frontera de tenancy de `clients/`.
        Schema::create('accommodation_client', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('accommodation_id')->index();
            $table->foreignId('client_id')->index()->constrained('clients')->cascadeOnDelete();
            $table->enum('status', ['active', 'rejected'])->default('active');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->index()->constrained('invitations')->nullOnDelete();
            $table->timestamps();

            $table->unique(['accommodation_id', 'client_id']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->foreignId('document_type_id')->nullable()->index()->constrained('document_types')->nullOnDelete();
            $table->string('title');
            $table->string('disk')->default('documents');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('client_id')->index()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('shared_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('shared_at')->useCurrent();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'client_id']);
        });

        Schema::create('client_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('entity_type')->default('accommodation');
            $table->foreignId('document_type_id')->index()->constrained('document_types')->cascadeOnDelete();
            $table->boolean('required')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'entity_type', 'document_type_id'], 'client_doc_requirement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_document_requirements');
        Schema::dropIfExists('document_shares');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('accommodation_client');
        Schema::dropIfExists('invitations');
    }
};
