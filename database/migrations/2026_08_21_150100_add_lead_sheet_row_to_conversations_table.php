<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-045 · Donde queda escrita la fila que ocupa el lead en la planilla.
 *
 * El ticket lo declara como hueco: *"no hay columna donde persistir la
 * referencia de fila para el upsert de AC-13.2"*. Esta es esa columna.
 *
 * ## Por que no va en `context_data`
 *
 * Seria mas barato y esta mal. `PasoEntregado` **restituye `context_data`
 * entero** cuando el mensaje del paso no se entrega, y `MaquinaDeEstados` lo
 * limpia al reiniciar la conversacion con "menu" (AC-16.3). En los dos casos la
 * referencia se perderia y el lead volveria a aparecer en la planilla como una
 * fila nueva — que es exactamente lo que AC-13.2 prohibe.
 *
 * Como columna propia, ninguno de esos dos caminos la toca.
 *
 * ## Por que la referencia lleva tambien la planilla
 *
 * Una fila sin su planilla es un numero suelto. Si la duenia cambia de planilla
 * (AC-26.4), escribir "la fila 2" sobre la planilla nueva pisa a **otra
 * persona**: no se pierde un lead, se arruina uno que estaba bien. Guardando las
 * dos, el volcado compara y, si no coinciden, no toca nada preexistente.
 *
 * ⚠️ No se llama `spreadsheet_id` a proposito: ese nombre es el de la
 * vinculacion en `lead_spreadsheets`, que es la que lleva el unico de la
 * decision § 11. Repetirlo aca haria parecer que esta columna tambien vincula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('lead_sheet_id', 191)->nullable()->after('context_data');
            $table->unsignedInteger('lead_sheet_row')->nullable()->after('lead_sheet_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['lead_sheet_id', 'lead_sheet_row']);
        });
    }
};
