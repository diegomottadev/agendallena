<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-048 · Historial de mensajes.
 *
 * AC-14.4 promete "el historial completo de mensajes" y no habia tabla:
 * `conversations` guarda `current_state`, `context_data` y `last_interaction_at`
 * — el **estado** de la conversacion, no la conversacion.
 *
 * Adelantada respecto del sprint 8 por decision explicita: el dato que no se
 * guarda hoy no se recupera despues. Tambien la necesita AC-08.1, que pide que
 * un mensaje recibido durante una pausa humana quede registrado.
 *
 * ## Retencion
 *
 * **12 meses.** Es la tabla de mayor crecimiento del sistema y la unica que
 * guarda contenido de conversaciones de terceros: el cliente final de la PyME
 * no es usuario nuestro y no acepto ningun termino con nosotros. Doce meses
 * cubre el ciclo de estacionalidad completo de un negocio —que es lo que
 * cualquier reporte anual necesita— sin volverse un archivo indefinido de
 * conversaciones ajenas.
 *
 * ⚠️ **La politica esta definida, no aplicada.** No hay tarea de borrado
 * todavia: el alcance de T-048 pide definirla y documentarla. Antes del primer
 * cliente real hay que agendar el purgado, o la tabla crece sin techo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreignId('conversation_id')
                ->constrained('conversations')->cascadeOnDelete()->cascadeOnUpdate();

            // `inbound` lo escribe el cliente final; `outbound` lo manda el bot
            // o el operador.
            $table->enum('direction', ['inbound', 'outbound']);

            // `text`, `interactive`, `image`, `template`, ... Lo define Meta y
            // cambia con el tiempo: va como string y no como enum para no tener
            // que migrar la tabla mas grande del sistema cada vez que Meta
            // agrega un tipo.
            $table->string('type', 50);

            // Texto plano del mensaje cuando lo hay. El payload completo queda
            // en `payload` para los tipos que no son texto (botones, listas).
            $table->text('content')->nullable();
            $table->json('payload')->nullable();

            // El `wamid`. Nulo en un saliente hasta que Meta lo confirma.
            $table->string('whatsapp_message_id')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            /*
             * Consulta del historial de una conversacion, ordenado.
             *
             * ⚠️ **Medido con 6.000 filas y un solo tenant, MySQL NO lo elige:**
             * prefiere el indice de la foranea `(conversation_id)` y resuelve el
             * `ORDER BY` con un filesort. Es correcto de su parte — con un unico
             * tenant la columna lider tiene cardinalidad 1 y no discrimina nada.
             *
             * Forzando este indice, el plan pasa a `Backward index scan` sin
             * filesort, o sea que el indice **si** sirve el orden cuando se usa.
             * Con varios tenants la cardinalidad sube y el optimizador deberia
             * elegirlo solo.
             *
             * No se compensa con un indice `(conversation_id, created_at)`
             * adicional: el filesort actual es sobre ~30 filas ya filtradas, y
             * esta es la tabla de mayor crecimiento del sistema — un indice de
             * mas se paga en cada insert, para siempre. **Volver a medirlo con
             * datos reales de varios tenants antes de tocar nada.**
             */
            $table->index(['tenant_id', 'conversation_id', 'created_at']);

            // Idempotencia: si el job se reintenta despues de haber persistido el
            // mensaje pero antes de terminar, el reintento no puede duplicar la
            // fila. En MySQL un unico admite varios NULL, asi que no estorba a
            // los salientes que todavia no tienen `wamid`.
            $table->unique(['tenant_id', 'whatsapp_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
