<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla base del modelo multi-tenant (RNF-01: aislamiento por columna `tenant_id`).
 * DDL de referencia: .claude/docs/02-arquitectura/04-modelo-de-datos.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->enum('status', ['active', 'suspended', 'trial'])->default('trial');
            // RNF-02: la base guarda UTC; esta zona se usa solo para convertir en el borde.
            $table->string('timezone', 50)->default('America/Argentina/Buenos_Aires');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
