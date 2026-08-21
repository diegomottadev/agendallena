<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de la conversacion por (tenant, telefono). Fuente de verdad de la
 * maquina de estados de T-018; Redis `session:` es solo cache de lectura.
 * DDL de referencia: .claude/docs/02-arquitectura/04-modelo-de-datos.md
 *
 * El indice (tenant_id, user_phone) lo agrega T-009: es la resolucion de
 * conversacion en cada webhook, camino critico del RNF-04.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('user_phone', 50);
            $table->string('user_name')->nullable();
            $table->string('current_state', 100)->default('IDLE');
            $table->json('context_data')->nullable();
            // RNF-02: se guarda en UTC, igual que el resto de los timestamps.
            $table->timestamp('last_interaction_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->index(['tenant_id', 'user_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
