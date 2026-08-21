<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-024 · Ventana de búsqueda de disponibilidad, configurable.
 *
 * El ticket lo marca como decisión abierta: *"sin definir la ventana de búsqueda
 * por defecto ni la ampliada. Ningún documento dice cuántos días hacia adelante
 * se consultan; hoy sería una constante implícita. Definirlo en este ticket y
 * hacerlo configurable."*
 *
 * **Por defecto 7 días.** Es lo que hace que "no hay lugar" signifique algo: con
 * una ventana de 30 días, una agenda razonablemente ocupada casi nunca daría
 * vacío, y el mensaje de agenda llena —que es donde el producto puede convertir
 * un "no" en una venta— no se dispararía nunca. Con 7 días, "no tengo lugar esta
 * semana" es una frase que el cliente entiende y que abre la conversación.
 *
 * El spike T-005 midió que **ampliar la ventana no cuesta latencia** (7, 30 y 90
 * días miden igual), así que el número es una decisión de producto y no técnica:
 * se elige por lo que le decimos al cliente, no por lo que cuesta preguntarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('search_window_days')
                ->default(7)
                ->after('buffer_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn('search_window_days');
        });
    }
};
