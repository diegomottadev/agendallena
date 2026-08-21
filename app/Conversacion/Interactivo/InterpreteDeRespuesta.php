<?php

namespace App\Conversacion\Interactivo;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

/**
 * T-019 · Qué significa la respuesta que mandó el cliente.
 *
 * **No interpreta texto libre**, y es un criterio del ticket, no una limitación:
 * *"la respuesta de un botón se resuelve a una transición sin interpretar texto
 * libre"*. Adivinar la intención de un texto es una superficie de error enorme
 * para un producto cuyo KPI es que el cliente toque en vez de escribir.
 */
class InterpreteDeRespuesta
{
    /**
     * @param  array<string,mixed>  $mensaje  El mensaje crudo de Meta.
     */
    public function interpretar(array $mensaje, Conversation $conversacion): RespuestaInteractiva
    {
        $id = $this->idDeLaRespuesta($mensaje);

        if ($id === null) {
            // Texto libre, audio, imagen: nada que resolver a una transición.
            return RespuestaInteractiva::noInteractiva();
        }

        $sello = IdSellado::leer($id);

        if ($sello === null) {
            /*
             * Tiene forma de respuesta interactiva pero el `id` no es nuestro.
             * Pasa con plantillas viejas o con botones de una plantilla aprobada
             * en Meta, cuyos `id` los define el portal y no este código.
             */
            Log::info('Respuesta interactiva con un id que no emitimos nosotros', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'codigo' => 'META_ID_DESCONOCIDO',
            ]);

            return RespuestaInteractiva::idDesconocido($id);
        }

        /*
         * AC-03.2 · El sello no coincide: el cliente scrolleó y tocó un botón de
         * un mensaje anterior. **No se ejecuta la acción vieja.** Quien recibe
         * esto le responde con el paso actual, que es lo que el criterio pide.
         */
        if (! $sello->siguevigente($conversacion)) {
            Log::info('Botón de un mensaje anterior: se ignora la acción', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'accion' => $sello->accion,
                'emitido_en' => $sello->estadoEmisor->value,
                'version_emitida' => $sello->version,
                'estado_actual' => $conversacion->current_state,
                'version_actual' => (int) $conversacion->state_version,
                'codigo' => 'META_BOTON_CADUCO',
            ]);

            return RespuestaInteractiva::caduca($sello);
        }

        return RespuestaInteractiva::vigente($sello);
    }

    /**
     * El `id` que devuelve Meta, según el tipo de respuesta.
     *
     * @param  array<string,mixed>  $mensaje
     */
    private function idDeLaRespuesta(array $mensaje): ?string
    {
        return $mensaje['interactive']['button_reply']['id']
            ?? $mensaje['interactive']['list_reply']['id']
            // Los botones de una plantilla aprobada llegan en otra forma: no
            // llevan `id` propio sino el payload que se fijó al enviarla.
            ?? $mensaje['button']['payload']
            ?? null;
    }
}
