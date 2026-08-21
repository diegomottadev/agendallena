<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-035 · La marca de derivación a una persona (AC-20.1).
 *
 * ## Por qué son tres columnas y no un booleano
 *
 * Un `derivada = true` alcanzaría para pintar la fila en el panel y para nada
 * más. AC-20.1 pide **motivo y hora**, y el motivo es el dato que US-20 dice
 * que vinimos a buscar: si domina `unknown_input` el problema está en el flujo
 * conversacional, si domina `no_availability` hay un problema de capacidad que
 * es una oportunidad comercial.
 *
 * `handoff_resolved_at` en vez de borrar la marca al resolver: limpiar las dos
 * primeras columnas dejaría la lista de pendientes igual de correcta pero
 * **perdería el histórico por causa**, que es justo lo que hace falta para
 * contarlas.
 *
 * ⚠️ Sin definir qué pasa con una derivación que nadie atiende: no hay ventana
 * de vencimiento ni columna para ella. La decisión sigue abierta en US-20 y no
 * se inventa acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            /*
             * `string` y no `enum`: el tercer motivo del enum de la aplicación
             * —`out_of_range`— **todavía no tiene disparador** porque el
             * cotizador está diferido (T-016, T-040, T-041). Un `ENUM` de MySQL
             * obligaría a una migración de esquema para agregar el que falte.
             */
            $table->string('handoff_reason', 50)->nullable()->after('context_data');
            // RNF-02 · UTC, como todo el resto. Se convierte recién en el panel.
            $table->timestamp('handoff_at')->nullable()->after('handoff_reason');
            $table->timestamp('handoff_resolved_at')->nullable()->after('handoff_at');

            /*
             * El índice de la única consulta que corre seguido: los pendientes
             * de este tenant, ordenados por antigüedad (AC-20.2). Lidera con
             * `tenant_id` como todos los compuestos del proyecto; sigue el
             * filtro de igualdad (`handoff_resolved_at IS NULL`) y cierra con la
             * columna por la que se ordena, así el `ORDER BY` sale del índice.
             */
            $table->index(['tenant_id', 'handoff_resolved_at', 'handoff_at'], 'conversations_handoff_pendientes_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_handoff_pendientes_index');
            $table->dropColumn(['handoff_reason', 'handoff_at', 'handoff_resolved_at']);
        });
    }
};
