<?php

namespace App\Conversacion;

use App\Models\Conversation;

/**
 * RNF-03 · Un paso del flujo que el cliente **no recibió** no queda aplicado.
 *
 * ## El silencio que produce el orden transicionar → enviar
 *
 * Los pasos del flujo mueven el estado y después mandan el mensaje. Si el envío
 * se cae, el estado ya avanzó — y el reintento de la cola entra con la
 * conversación en el paso siguiente:
 *
 * 1. El cliente escribe su nombre. La conversación pasa a `SELECTING_SLOT` y el
 *    envío de la lista se cae con un rate limit.
 * 2. La cola reintrega el mismo mensaje. Ahora el estado ya no es
 *    `GATHERING_PARAMS`, así que nadie vuelve a tomar el nombre: el mensaje cae
 *    en «no te entiendo» y **el intento termina sin excepción**.
 * 3. Para la cola, el mensaje se procesó bien. `failed()` no corre nunca, el
 *    fallback de AC-09.4 no se despliega, y el cliente se queda esperando una
 *    lista que ya nadie va a mandar.
 *
 * El daño no es que el estado quede mal: es que **el reintento deja de reintentar
 * lo mismo**, y con eso se apaga toda la red de recuperación justo cuando hacía
 * falta.
 *
 * ## Qué hace
 *
 * Toma una foto del paso antes de ejecutarlo y, si el mensaje no se entregó,
 * la restituye. El siguiente intento vuelve a recorrer el mismo camino y falla
 * igual —o entrega, si Meta se recuperó—, que es lo que la cola necesita para
 * que sus reintentos signifiquen algo.
 *
 * No se traga nada: la excepción se vuelve a lanzar tal cual, porque es la que
 * le dice a la cola que hay que reintentar.
 */
class PasoEntregado
{
    public function __construct(private readonly MaquinaDeEstados $maquina) {}

    /**
     * Ejecuta un paso y lo deshace si el mensaje no llegó.
     *
     * @param  \Closure():?string  $paso  Transiciona y envía. Devuelve el `wamid`
     *   entregado, o `null` si Meta rechazó el envío sin lanzar.
     * @return string|null  El `wamid` del mensaje que el cliente recibió.
     */
    public function ejecutar(Conversation $conversacion, \Closure $paso): ?string
    {
        $conversacion->refresh();

        $estadoPrevio = Estado::from($conversacion->current_state);
        $contextoPrevio = $conversacion->context_data;

        try {
            $wamid = $paso();
        } catch (\Throwable $e) {
            $this->deshacer($conversacion, $estadoPrevio, $contextoPrevio, $e::class);

            throw $e;
        }

        if ($wamid === null) {
            $this->deshacer($conversacion, $estadoPrevio, $contextoPrevio, null);
        }

        return $wamid;
    }

    /**
     * @param  array<string,mixed>|null  $contextoPrevio
     */
    private function deshacer(Conversation $conversacion, Estado $estadoPrevio, ?array $contextoPrevio, ?string $causa): void
    {
        $conversacion->refresh();

        /*
         * Si nada avanzó, no hay nada que deshacer — y hacerlo igual subiría la
         * versión, caducando botones que el cliente todavía tiene vigentes en el
         * chat. Un paso que no transiciona y cuyo envío falla ya vuelve a
         * recorrerse igual en el reintento.
         */
        if ($conversacion->current_state === $estadoPrevio->value
            && $conversacion->context_data == $contextoPrevio) {
            return;
        }

        $this->maquina->deshacer($conversacion, $estadoPrevio, $contextoPrevio, $causa);
    }
}
