<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El número por el que atiende una persona.
 *
 * ## Por qué hace falta
 *
 * **Un número registrado en la Cloud API deja de poder usarse como chat humano.**
 * O es bot, o es persona: no las dos cosas. Eso invalida la premisa sobre la que
 * estaban escritas tres piezas del backlog:
 *
 * - **AC-08.1** — *"respondí manualmente desde la app móvil"*: imposible.
 * - **T-004** — el spike preguntaba si Meta entrega esos mensajes por webhook.
 *   La pregunta ya no aplica; el ticket se cierra sin ejecutarlo.
 * - **T-035** — *"el equipo responde desde WhatsApp, que es donde ya trabaja"*:
 *   falso. Ahí ya no trabaja nadie.
 *
 * ## El modelo elegido: dos números
 *
 * El bot vive en el número de la Cloud API; las personas atienden en otro,
 * normal. Al derivar, el bot **dice a dónde escribir**.
 *
 * El costo es real y conviene tenerlo escrito: **el cliente tiene que cambiar de
 * chat y volver a explicar su caso**, y una parte no lo va a hacer. Se eligió
 * igual porque no requiere código nuevo de atención, y porque cuántos se pierden
 * es justo lo que el piloto puede medir. La alternativa —contestar desde nuestro
 * panel vía la API— queda disponible si el número duele.
 *
 * ⚠️ Nullable a propósito: un tenant sin segundo número es un caso real, y el
 * mensaje de derivación tiene que seguir siendo honesto sin él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('human_phone', 50)->nullable()->after('no_availability_message');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn('human_phone');
        });
    }
};
