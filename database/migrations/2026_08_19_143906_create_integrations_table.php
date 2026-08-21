<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales de terceros por tenant.
 * DDL de referencia: .claude/docs/02-arquitectura/04-modelo-de-datos.md
 *
 * Dos desvios respecto del DDL, ambos deliberados:
 *
 * 1. `access_token` es nullable. El DDL lo declara NOT NULL, pero una integracion
 *    de `meta_whatsapp` existe desde que se configura el webhook (T-008) y puede
 *    no tener token todavia. Forzar NOT NULL invita a guardar cadenas vacias, que
 *    es peor que un NULL explicito.
 *
 * 2. Indice unico sobre (provider, account_identifier). El DDL no lo define y sin
 *    el no hay forma de resolver el `phone_number_id` que manda Meta a un tenant:
 *    es el mapeo que necesita la ingesta de T-010.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->enum('provider', [
                'meta_whatsapp',
                'google_calendar',
                'outlook_calendar',
                'google_sheets',
            ]);
            // Para meta_whatsapp guarda el `phone_number_id`; para Google, la cuenta.
            $table->string('account_identifier');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Encriptado en reposo desde el modelo. Aca vive el verify_token de Meta.
            $table->text('settings')->nullable();
            $table->enum('status', ['connected', 'disconnected', 'expired'])->default('connected');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->index(['tenant_id', 'provider']);
            $table->unique(['provider', 'account_identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
