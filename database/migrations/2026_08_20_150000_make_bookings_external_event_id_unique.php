<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-030 · AC-22.3 · `(tenant_id, external_event_id)` pasa a ser **unico**.
 *
 * ## Por que el candado tiene que estar en la base
 *
 * La clave de idempotencia hacia Google evita el segundo *evento*; esto evita
 * la segunda *fila*. No son lo mismo: si dos workers reintentan el mismo
 * agendamiento a la vez, los dos reciben el `409` de Google y los dos siguen
 * hacia el `INSERT`. Un `SELECT` previo no los separa —los dos ven "no esta"—;
 * el unico indice si.
 *
 * Es la misma decision que `notification_logs` (T-009) y `processed_messages`
 * (T-011): la garantia la da el esquema, no el orden en que corran los procesos.
 * Con dos filas para el mismo turno, el cliente recibe dos recordatorios y la
 * PyME paga las dos plantillas.
 *
 * ## Sigue siendo el indice de lectura de T-009
 *
 * El indice `(tenant_id, external_event_id)` que T-009 agrego para resolver la
 * notificacion entrante de Google (T-027) se reemplaza por el unico sobre las
 * mismas columnas y en el mismo orden: la consulta usa el unico igual, asi que
 * no se pierde el plan de ejecucion verificado con `EXPLAIN`.
 *
 * `tenant_id` lidera, como todo indice compuesto del proyecto (RNF-01).
 *
 * ⚠️ **No es unico por `external_event_id` solo, a proposito.** Google no
 * garantiza que dos cuentas distintas no repitan un `id`, y dos PyMEs pueden
 * tener el mismo: hacerlo unico global dejaria a la segunda sin poder agendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'external_event_id']);
            $table->unique(['tenant_id', 'external_event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_event_id']);
            $table->index(['tenant_id', 'external_event_id']);
        });
    }
};
