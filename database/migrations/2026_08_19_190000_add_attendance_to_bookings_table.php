<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-036 · Registro de asistencia en `bookings`.
 *
 * ## Por que columna propia y no un valor mas de `status`
 *
 * Es la parte importante del ticket; la migracion en si es trivial.
 *
 * `status` responde "que paso con la reserva" (`scheduled`, `confirmed`,
 * `rescheduled`, `cancelled`). La asistencia responde otra pregunta: "vino o
 * no". Son ortogonales — un turno puede estar **`confirmed` y `no_show` a la
 * vez**, y de hecho ese es el caso mas interesante del negocio.
 *
 * Si `no_show` fuera un valor de `status`, marcar la ausencia **pisaria** el
 * dato de que ese cliente habia confirmado. Eso rompe AC-17.4, que es el
 * criterio que US-17 declara mas importante: sin poder cruzar asistencia contra
 * respuesta al recordatorio, se observa que el ausentismo bajo pero no se le
 * puede atribuir causa — y atribuir esa causa es el argumento de venta entero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('attendance', ['pending', 'attended', 'no_show'])
                ->default('pending')
                ->after('status');

            /*
             * Auditoria: quien marco y cuando. US-17 lo necesita para distinguir
             * una ausencia registrada por el negocio de una fila nunca tocada.
             *
             * ⚠️ `foreignId` (BIGINT) y no `uuid`: `users.id` es
             * `bigint unsigned` en el codigo, aunque `04-modelo-de-datos.md` lo
             * documente como `CHAR(36)` UUID "para evitar la enumeracion de
             * recursos". La migracion de usuarios quedo con el default de
             * Laravel y nadie la adapto. **Esta columna sigue al codigo, que es
             * lo que existe.** Unificar los dos es una decision que pertenece a
             * T-046 (autenticacion del panel), no a este ticket: cambiar la PK
             * de `users` con `personal_access_tokens` ya apuntando ahi no es una
             * migracion de un punto.
             */
            $table->foreignId('attendance_marked_by')->nullable()->after('attendance')
                ->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('attendance_marked_at')->nullable()->after('attendance_marked_by');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['attendance_marked_by']);
            $table->dropColumn(['attendance', 'attendance_marked_by', 'attendance_marked_at']);
        });
    }
};
