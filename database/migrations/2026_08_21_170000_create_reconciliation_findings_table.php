<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * § 8 y § 9 · Los desalineados que la conciliacion detecta y **una persona
 * resuelve**. Decision del 2026-08-21.
 *
 * Hasta hoy la conciliacion que encontraba un turno vivo cuyo evento el dueno
 * habia borrado de su Google Calendar **lo cancelaba**, y no persistia nada:
 * solo emitia una linea de log. Las dos mitades estaban mal. Cancelar en
 * silencio deja al cliente sin enterarse —tiene la confirmacion en el celular y
 * llega a la puerta—, y avisarle automaticamente choca con la ventana de 24 h de
 * Meta, que exige una plantilla aprobada que no existe. Ahora el turno queda
 * **en duda**: una fila aca, que el panel lista, que una persona mantiene o
 * cancela.
 *
 * ## Por que una tabla propia y no un valor mas de `bookings.status`
 *
 * Es el mismo error que T-036 corrigio al sacar la asistencia de `status`.
 * `status` responde *"que paso con la reserva"*; esto responde otra pregunta:
 * *"detectamos una inconsistencia entre los dos lados"*. Son ortogonales — un
 * turno puede estar `confirmed` **y** en duda a la vez, y esa combinacion es
 * justamente la que la pantalla existe para resolver.
 *
 * ## ⚠️ El candado retiene tambien despues de resuelto, y es lo contrario de
 * `live_event_id`
 *
 * `unique(tenant_id, type, booking_id)`, **sin ninguna condicion sobre
 * `resolved_at`**: un hallazgo por turno y por tipo, para siempre, lo hayan
 * resuelto o no.
 *
 * La tentacion es copiar el truco de `live_event_id` —una columna generada que
 * suelta el candado cuando la fila deja de estar viva—, y seria exactamente lo
 * contrario de lo que hace falta. Alla la fila cancelada **tiene que soltarlo**:
 * el horario se libero y otro cliente lo tiene que poder reservar. Aca la fila
 * resuelta **tiene que retenerlo**, porque *«mantener» no recrea el evento en
 * Google*: despues de resolver, el turno sigue vivo en la base y sigue ausente
 * del calendario, o sea que **el desalineado que lo genero no desaparecio** y la
 * corrida de dentro de quince minutos lo vuelve a ver. Si el candado se soltara,
 * se insertaria un hallazgo nuevo y el dueno resolveria lo mismo cada quince
 * minutos hasta que deje de mirar la pantalla.
 *
 * El hallazgo **no se cierra porque el mundo cambio: se cierra porque una
 * persona decidio**, y esa decision no caduca a los quince minutos.
 *
 * ⚠️ Y tiene que estar **en el esquema**, no en un `SELECT` previo del comando:
 * una comprobacion en PHP —*"fijate si ya hay un hallazgo de este turno"*— deja
 * abierta la ventana entre el `SELECT` y el `INSERT`, y dos workers que
 * concilian a la vez la pasan los dos. Es la leccion que ya dejaron
 * `live_event_id` y `lead_spreadsheets`: el motor es el unico que lo puede
 * garantizar.
 *
 * El precio conocido: si el dueno vuelve a crear el evento con el mismo `id` y
 * lo borra otra vez, ese segundo borrado **no** genera hallazgo nuevo. Es el
 * lado correcto del error —una pantalla que se repite es una pantalla que nadie
 * mira—, pero queda anotado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');

            $table->foreignId('booking_id')
                ->constrained('bookings')->cascadeOnDelete()->cascadeOnUpdate();

            /*
             * `string` y no `enum`: este es el catalogo de desalineados que la
             * conciliacion sabe detectar, y va a crecer —el huerfano, el evento
             * movido de hora—. Un `enum` obligaria a una migracion por tipo
             * nuevo sobre una tabla que ya tiene datos, a cambio de nada: el
             * valor lo fija una constante del modelo, no la entrada de un humano.
             *
             * 64 y no 255 porque entra en el unico de abajo, y el limite de un
             * indice de MySQL 8 con `utf8mb4` es de 3072 bytes.
             */
            $table->string('type', 64);

            /*
             * Cuando lo vio la corrida. Es el dato que ordena la pantalla y el
             * que deja ver hace cuanto que algo esta sin resolver.
             *
             * ⚠️ UTC, como toda columna de fecha del proyecto. La conversion a
             * la zona del negocio ocurre al mostrarlo, via `HoraLocal` (RNF-02).
             */
            $table->timestamp('detected_at');

            /*
             * Quien, cuando y que decidio. `NULL` en los tres = pendiente, que es
             * lo unico que la pantalla lista.
             *
             * La auditoria es la misma que `attendance_marked_by` de T-036 y por
             * el mismo motivo: es una decision humana sobre un turno real. Cuando
             * dentro de dos semanas el cliente llegue y no haya turno, saber
             * quien decidio mantenerlo —o cancelarlo— es la unica forma de
             * reconstruir que paso.
             *
             * ⚠️ `foreignId` (BIGINT) y no `uuid`, igual que
             * `attendance_marked_by`: `users.id` es `bigint unsigned` en el
             * codigo aunque el modelo de datos lo documente como UUID. Esta
             * columna sigue al codigo, que es lo que existe.
             */
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()
                ->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->string('resolution', 32)->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            /*
             * El candado. Lidera con `tenant_id` como todo indice compuesto del
             * proyecto (RNF-01), lo que ademas lo deja servir el listado del
             * panel, que siempre filtra por tenant.
             *
             * **Sin condicion sobre `resolved_at`** — ver la nota de arriba, que
             * es la parte importante de esta migracion.
             */
            $table->unique(['tenant_id', 'type', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_findings');
    }
};
