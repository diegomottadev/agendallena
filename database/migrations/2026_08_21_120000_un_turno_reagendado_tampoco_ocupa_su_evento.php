<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T-042 · `live_event_id` tiene que soltar tambien al turno **re-agendado**.
 *
 * ## Que estaba mal
 *
 * La migracion del 2026-08-21 hizo que el unico sobre el evento valiera solo
 * entre turnos vivos, y definio "vivo" como *"cualquier estado menos
 * `cancelled`"*. Era cierto ese dia: `rescheduled` figuraba en el ENUM y ningun
 * codigo lo escribia, porque T-042 estaba diferido.
 *
 * T-042 lo empezo a escribir, y con eso **reabrio el mismo defecto con otro
 * estado**. El camino es este y no es raro:
 *
 * 1. El cliente tiene turno a las 15 — evento `K`, donde `K` es la clave de
 *    idempotencia de (tenant, conversacion, 15:00).
 * 2. Toca Re-agendar y lo mueve a las 16. La fila vieja queda `rescheduled` y
 *    **su evento `K` se borra de Google**.
 * 3. Se arrepiente y vuelve a pedir las 15.
 *
 * `ClaveDeIdempotencia` es deterministica a proposito, asi que el turno nuevo de
 * las 15 pide otra vez el evento `K`. El `INSERT` choca contra la fila
 * `rescheduled` —que sigue reclamando `K` aunque ese evento ya no exista— y
 * `Reserva::turnoYaPersistido()` la devuelve como si el turno acabara de
 * agendarse: el cliente recibe *"tu turno quedo reservado"* sin turno vivo ni
 * evento en el calendario.
 *
 * ## Que cambia
 *
 * "Vivo" pasa a significar **ni cancelado ni re-agendado**. Los dos son estados
 * en los que el evento de Google ya no nos pertenece: en el primero lo borramos
 * al cancelar, en el segundo al mover el turno.
 *
 * La fila conserva su `external_event_id` en los dos casos — que un cliente
 * cancelo, o que movio su turno y a que evento, es justo lo que el piloto va a
 * querer mirar.
 *
 * ## Por que `DB::statement` y no el `Blueprint`
 *
 * Cambiar la expresion de una columna generada es un `MODIFY COLUMN`, y el
 * `virtualAs()` de Laravel emite un `ADD COLUMN`. El indice unico que la usa
 * **no hace falta tocarlo**: sigue siendo el mismo indice sobre la misma
 * columna, y MySQL lo recalcula al redefinir la expresion.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE bookings MODIFY COLUMN live_event_id VARCHAR(255) '
            ."GENERATED ALWAYS AS (case when `status` in ('cancelled', 'rescheduled') "
            .'then null else `external_event_id` end) VIRTUAL'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE bookings MODIFY COLUMN live_event_id VARCHAR(255) '
            ."GENERATED ALWAYS AS (case when `status` = 'cancelled' "
            .'then null else `external_event_id` end) VIRTUAL'
        );
    }
};
