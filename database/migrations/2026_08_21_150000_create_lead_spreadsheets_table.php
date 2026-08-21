<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-044 · La planilla de Google Sheets donde la PyME recibe sus leads.
 *
 * ## Por que una tabla propia y no una columna en `integrations`
 *
 * `integrations` tiene el unico `(provider, account_identifier)` y una fila por
 * cuenta conectada: la planilla no es una cuenta, es una eleccion sobre la
 * cuenta de Google que ya esta conectada. Meterla ahi obligaria a inventarle un
 * `account_identifier` a una fila que no representa ninguna conexion.
 *
 * ## Los dos unicos son el candado, y estan en el esquema a proposito
 *
 * - **`tenant_id` unico**: un negocio, una planilla. Sin esto, dos filas dejarian
 *   al volcado eligiendo a cual escribir por orden de `SELECT`, que no es una
 *   decision de producto (AC-26.4).
 * - **`spreadsheet_id` unico, solo**, sin `tenant_id` adelante: es la decision
 *   § 11 —dos negocios no pueden compartir planilla—. Un
 *   `(tenant_id, spreadsheet_id)` se ve parecido y permite exactamente lo que la
 *   decision prohibe: los clientes de una PyME a la vista de otra, que es la
 *   fuga que RNF-01 existe para impedir.
 *
 * ⚠️ **Es el unico lugar donde puede estar.** Una comprobacion en PHP —"fijate
 * si esa planilla ya esta tomada"— deja abierta la ventana entre el `SELECT` y
 * el `INSERT`: dos workers que miran a la vez pasan los dos. Es la leccion que
 * dejo `live_event_id`, y el motor es el unico que la puede garantizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_spreadsheets', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            /*
             * 191 y no 255: con `utf8mb4` el limite de un indice de MySQL 8 es
             * de 3072 bytes, y una columna indexada de 255 se acerca al techo
             * sin necesidad. Un `spreadsheetId` de Google son 44 caracteres.
             */
            $table->string('spreadsheet_id', 191);
            // La hoja que eligio la duenia. Su planilla tiene otras, y escribir
            // en la primera que haya le pisa la que ya estaba usando.
            $table->string('sheet_name', 191);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->unique('tenant_id');
            $table->unique('spreadsheet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_spreadsheets');
    }
};
