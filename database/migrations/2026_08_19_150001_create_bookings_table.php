<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos agendados. `external_event_id` es el id del evento en Google Calendar.
 * DDL de referencia: .claude/docs/02-arquitectura/04-modelo-de-datos.md
 *
 * `status` no incluye asistencia (attended/no_show): eso es T-036, en columna
 * aparte para no perder el dato de que un turno "confirmed" habia confirmado.
 *
 * Los dos indices los agrega T-009:
 * - (tenant_id, start_time, status): consulta del scheduler cada 10 minutos.
 * - (tenant_id, external_event_id): resolucion de la notificacion entrante
 *   de Google (T-027). No es unico: el ticket solo pide unicidad para
 *   notification_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreignId('conversation_id')->nullable()
                ->constrained('conversations')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('integration_id')
                ->constrained('integrations')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('external_event_id');
            $table->string('client_name');
            $table->string('client_phone', 50);
            $table->string('service_name', 150);
            $table->decimal('quote_amount', 10, 2)->nullable();
            // RNF-02: UTC en reposo, se convierte a la zona del tenant solo en el borde.
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->enum('status', ['scheduled', 'confirmed', 'rescheduled', 'cancelled'])
                ->default('scheduled');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->index(['tenant_id', 'start_time', 'status']);
            $table->index(['tenant_id', 'external_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
