<?php

namespace App\Services\Google;

use Carbon\CarbonInterface;

/**
 * T-030 · AC-22.3 · La clave que hace idempotente la creación del evento.
 *
 * `events.insert` de Google no tiene cabecera de idempotencia: el único
 * mecanismo es **fijar el `id` del evento desde el cliente**. Un segundo
 * `insert` con el mismo `id` no crea nada y contesta `409 duplicate`; sin `id`,
 * Google inventa uno distinto por llamada y el reintento deja dos turnos sobre
 * el mismo horario, uno de ellos sin fila que lo reclame.
 *
 * ## De qué se deriva, y por qué de eso
 *
 * De las tres cosas que **no cambian entre dos intentos del mismo
 * agendamiento**: el tenant, la conversación y el instante de inicio en UTC.
 *
 * - **No entra el reloj** ni un aleatorio: el reintento ocurre después, y la
 *   clave tiene que salir igual.
 * - **No entra `state_version`**: sube al aplicar `SlotElegido` y el segundo
 *   intento la vería distinta.
 * - **Entra la conversación y el horario**, no solo el tenant: una clave que
 *   colapse dos agendamientos distintos le devolvería `409` al segundo cliente
 *   del día, que se quedaría sin turno. Es *idempotencia de más*, y falla igual
 *   de callada.
 *
 * ## El formato lo impone Google
 *
 * `id` tiene que estar en **base32hex** —`0-9` y `a-v`— y medir entre 5 y 1024
 * caracteres. Un hash hexadecimal lo cumple por construcción (`0-9a-f` es un
 * subconjunto); un `uuid()` con guiones o un base64 con mayúsculas **no**, y
 * Google contesta `400 invalid` sobre el camino donde se agenda.
 */
class ClaveDeIdempotencia
{
    /**
     * Caracteres del hash que se conservan.
     *
     * 40 hexadecimales son 160 bits: sobra para que dos agendamientos distintos
     * no colisionen, y el `id` sigue siendo legible en el calendario del dueño
     * si alguna vez tiene que buscarlo a mano.
     */
    private const LARGO = 40;

    /**
     * @param  string  $tenantId  UUID. Nunca `(int)`.
     * @param  int|string  $conversationId  La conversación que está agendando.
     * @param  CarbonInterface  $inicio  Instante del turno; se normaliza a UTC.
     */
    public static function paraTurno(string $tenantId, int|string $conversationId, CarbonInterface $inicio): string
    {
        /*
         * El instante se normaliza a UTC antes de entrar al hash: el mismo turno
         * expresado en la zona del tenant y en UTC tiene que dar la misma clave,
         * o el reintento mandaría otra según con qué `Carbon` llegue.
         */
        $semilla = implode('|', [
            $tenantId,
            (string) $conversationId,
            $inicio->copy()->utc()->format('YmdHis'),
        ]);

        return substr(hash('sha256', $semilla), 0, self::LARGO);
    }
}
