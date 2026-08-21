<?php

namespace App\Services\Agenda;

use App\Models\BusinessSetting;
use App\Models\Tenant;

/**
 * T-024 · Qué se le contesta al cliente según por qué no hay horarios.
 *
 * El último criterio del ticket es el que ordena esta clase: **ninguna rama de
 * la consulta de disponibilidad termina sin enviar un mensaje.** Por eso el
 * `match` sobre `Motivo` es exhaustivo y no tiene `default`: si mañana aparece
 * un motivo nuevo, PHP lanza en vez de dejar al cliente sin respuesta.
 *
 * Es el único punto del recorrido donde el producto puede convertir un "no" en
 * una venta: quien se topa con la agenda llena **todavía quiere comprar**.
 */
class RespuestaDeDisponibilidad
{
    /**
     * @return array{texto:string, opciones:array<int,string>}
     */
    public static function para(
        ResultadoDisponibilidad $resultado,
        Tenant $tenant,
        BusinessSetting $config,
    ): array {
        return match ($resultado->motivo) {

            Motivo::HayHorarios => [
                'texto' => 'Estos son los horarios que tengo disponibles:',
                'opciones' => [],
            ],

            /*
             * AC-19.1 · Explica el vacío **y ofrece una salida**. Un "no hay
             * turnos" a secas termina la conversación; ofrecer hablar con una
             * persona la mantiene abierta, que es de lo que se trata.
             *
             * ⚠️ El recorte Pareto difiere "ver más adelante" (AC-19.2), así que
             * la única salida hoy es la persona. Cuando vuelva, se suma acá.
             */
            Motivo::AgendaLlena => [
                'texto' => self::textoDeAgendaLlena($resultado, $config),
                /*
                 * ⚠️ **El copy cambió con T-035, no por gusto.** Esta opción se
                 * escribió cuando nadie la renderizaba; ahora sale como botón de
                 * Meta, y el título de un botón admite 20 caracteres. «Hablar con
                 * una persona» son 22: el cliente veía «Hablar con una…», que es
                 * justo la salida que no puede quedar ambigua.
                 */
                'opciones' => ['Hablar con alguien'],
            ],

            /*
             * AC-19.4 · **Un bug nuestro no se comunica como "no hay lugar".**
             * Se usa el texto de cortesía del tenant, el mismo de RNF-03: para el
             * cliente es indistinguible de una falla técnica, porque eso es.
             *
             * Decirle "no hay lugar" sería mentirle en la dirección que hace
             * perder la venta — se iría a buscar turno a otro lado por un
             * horario que nadie cargó.
             */
            Motivo::SinConfiguracion => [
                'texto' => $config->fallback_message,
                'opciones' => [],
            ],
        };
    }

    private static function textoDeAgendaLlena(
        ResultadoDisponibilidad $resultado,
        BusinessSetting $config,
    ): string {
        $texto = trim((string) $config->no_availability_message);

        if ($texto === '') {
            $texto = BusinessSetting::valoresPorDefecto()['no_availability_message'];
        }

        // El plazo consultado hace la diferencia entre "no tengo lugar" —que
        // suena a definitivo— y "no tengo lugar en los próximos 7 días", que
        // invita a preguntar por otra fecha.
        $dias = $resultado->diasConsultados;

        return $dias > 0
            ? $texto.' (busqué en los próximos '.$dias.' días.)'
            : $texto;
    }
}
