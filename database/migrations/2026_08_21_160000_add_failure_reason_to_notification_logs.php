<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-039 · Por que no salio el recordatorio, y cuando.
 *
 * `notification_logs.status` admitia `failed` desde el primer dia, asi que una
 * fila fallida decia *"algo paso"* y nada mas. Con eso el dueno ve que el
 * cliente no recibio el aviso y no puede saber si fue un numero mal cargado
 * —que arregla el— o un rechazo de Meta —que no—, y son dos acciones distintas.
 * Es la nota de riesgo textual del ticket.
 *
 * ## Por que `failed_at` no reusa `sent_at`
 *
 * `sent_at` significa **cuando salio** y queda `NULL` en un envio que nunca
 * salio: es lo que distingue el recordatorio entregado del que se intento. Si
 * el fallo escribiera ahi la hora, los dos casos se verian iguales en cualquier
 * consulta por fecha de envio, y la lista de fallidos de US-24 no podria
 * ordenarse por cuando fallo.
 *
 * ## El ENUM de `status` no se toca
 *
 * Ya declara `delivered` y `read` desde la migracion original, asi que los
 * webhooks de estado de Meta que consume este mismo ticket no necesitan
 * migracion propia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            /*
             * 500 y no `text`: lo que guarda es el `title` y el `message` que
             * manda Meta, o una frase nuestra. Una columna de texto largo no
             * cabe en un indice y este dato se muestra en una lista.
             */
            $table->string('failure_reason', 500)->nullable()->after('status');

            // UTC como toda `datetime` del proyecto (RNF-02). La conversion a la
            // zona del tenant ocurre al mostrarla, no al guardarla.
            $table->timestamp('failed_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn(['failure_reason', 'failed_at']);
        });
    }
};
