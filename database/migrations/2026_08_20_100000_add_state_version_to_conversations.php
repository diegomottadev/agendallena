<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-019 · Versión del estado, para sellar los botones (AC-03.2).
 *
 * ## Por qué no alcanza con sellar el estado emisor
 *
 * El criterio dice: *"avanzada la conversación a `SELECTING_SLOT`, tocar un
 * botón de un mensaje anterior hace que el bot responda con el paso actual y no
 * ejecute la acción vieja"*. Sellar el estado cubre eso.
 *
 * **Pero no cubre volver al mismo estado.** El bot ofrece horarios, el cliente no
 * responde, la conversación expira, vuelve a empezar y llega otra vez a
 * `SELECTING_SLOT` — ahora con horarios distintos. Si toca un botón de la lista
 * vieja, el estado **coincide** y el sello lo deja pasar: reservaría un horario
 * que ya no está en la lista, o peor, uno que mientras tanto se ocupó.
 *
 * El contador incremental resuelve eso: cada transición lo sube, así que ningún
 * botón emitido antes puede pasar por actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedInteger('state_version')->default(0)->after('current_state');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('state_version');
        });
    }
};
