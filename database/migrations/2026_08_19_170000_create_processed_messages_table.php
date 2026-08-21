<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-011 · Candado de idempotencia de la ingesta.
 *
 * Meta reentrega todo webhook que no recibio `200` a tiempo. Sin esto, cada
 * reentrega produce una segunda respuesta al mismo mensaje: el bot le habla dos
 * veces al cliente, que es exactamente lo que hace quedar mal a la PyME.
 *
 * Va en MySQL y no en Redis a proposito. Redis es cache en este proyecto
 * (`conversations` es fuente de verdad, Redis es cache y locks), y un `FLUSHALL`
 * o un desalojo por memoria reabriria la ventana de duplicados — un fallo que se
 * manifiesta como una respuesta duplicada a un cliente real, no como un error.
 *
 * ⚠️ Sin politica de purga: la tabla crece sin techo. A volumen de piloto son
 * pocas filas por dia, pero antes de escalar hay que borrar lo anterior a la
 * ventana de reintentos de Meta. No entra en T-011 porque su alcance no lo pide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            // El `wamid` de Meta. Es unico global, pero el unico lidera con
            // `tenant_id` por la regla del proyecto: ningun indice compuesto
            // arranca sin el.
            $table->string('message_id');
            $table->timestamp('processed_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            // El candado real. La deduplicacion no depende de una consulta previa
            // sino de que la base rechace el segundo insert: dos workers en
            // paralelo sobre la misma reentrega no pueden ganar los dos.
            $table->unique(['tenant_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_messages');
    }
};
