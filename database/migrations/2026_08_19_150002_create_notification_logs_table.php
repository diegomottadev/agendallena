<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de envios de WhatsApp por turno (recordatorios y confirmacion).
 * DDL de referencia: .claude/docs/02-arquitectura/04-modelo-de-datos.md
 *
 * El unico (booking_id, type) lo agrega T-009: es el candado contra
 * recordatorios duplicados (AC-10.4). Si T-039 habilita reenvios manuales,
 * este unico va a necesitar sumar un numero de intento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreignId('booking_id')
                ->constrained('bookings')->cascadeOnDelete()->cascadeOnUpdate();
            $table->enum('type', ['reminder_24h', 'reminder_2h', 'confirmation']);
            $table->string('whatsapp_message_id')->nullable();
            $table->enum('status', ['queued', 'sent', 'delivered', 'read', 'failed'])
                ->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->unique(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
