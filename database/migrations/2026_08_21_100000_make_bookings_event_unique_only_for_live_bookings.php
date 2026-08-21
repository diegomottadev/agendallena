<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-030 · El unico sobre el evento pasa a valer **solo entre turnos vivos**.
 *
 * ## Que estaba mal
 *
 * `ClaveDeIdempotencia` es deterministica a proposito: mismo tenant, misma
 * conversacion y mismo instante dan la misma clave, y eso es lo que hace que un
 * reintento converja al turno que ya existe en vez de duplicarlo.
 *
 * Pero la misma propiedad hace que **un turno nuevo despues de una cancelacion**
 * produzca la clave del turno cancelado. La fila `cancelled` seguia ocupando el
 * unico `(tenant_id, external_event_id)`, el `INSERT` de la fila nueva chocaba
 * contra ella, y `Reserva::turnoYaPersistido()` la devolvia como si el turno
 * acabara de agendarse: el cliente recibia *"tu turno quedo reservado"* sin que
 * hubiera ningun turno vivo en el panel ni recordatorio que enviarle.
 *
 * Cancelar y volver a sacar el mismo horario no es un caso raro.
 *
 * ## Que cambia
 *
 * El candado sigue siendo del esquema —no de una comprobacion en PHP, que deja
 * la ventana de carrera abierta entre dos workers—, pero ahora se aplica a lo
 * que de verdad tiene que ser unico: **un evento de Google no puede ser
 * reclamado por dos turnos vivos del mismo tenant a la vez**.
 *
 * `live_event_id` es una columna generada que vale `external_event_id` mientras
 * el turno este vivo y `NULL` cuando se cancela. En MySQL dos `NULL` no chocan
 * entre si en un unico, asi que la fila cancelada suelta el candado sola, sin
 * que nadie tenga que acordarse de limpiarla en cada camino de cancelacion.
 *
 * **La fila cancelada conserva su `external_event_id`**: que un cliente cancelo
 * y volvio a agendar el mismo horario es justo el dato que el piloto va a
 * querer mirar, y revivir la fila —o vaciarle el evento— lo borra.
 *
 * ## Sigue existiendo el indice de lectura de T-009
 *
 * El unico anterior era ademas el indice que resuelve la busqueda por
 * `(tenant_id, external_event_id)` —la conciliacion de T-030 y la notificacion
 * entrante de Google (T-027)—. Al dejar de existir, ese plan de ejecucion se
 * perderia, asi que el indice compuesto se vuelve a agregar explicitamente.
 *
 * `tenant_id` lidera los dos indices, como todo indice compuesto del proyecto
 * (RNF-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * La columna generada se agrega en su propio `ALTER`: el indice unico
         * que la usa no puede crearse en la misma sentencia que la crea.
         */
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('live_event_id')->nullable()
                ->virtualAs("case when `status` = 'cancelled' then null else `external_event_id` end")
                ->after('external_event_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_event_id']);
            $table->unique(['tenant_id', 'live_event_id']);
            $table->index(['tenant_id', 'external_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'external_event_id']);
            $table->dropUnique(['tenant_id', 'live_event_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('live_event_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unique(['tenant_id', 'external_event_id']);
        });
    }
};
