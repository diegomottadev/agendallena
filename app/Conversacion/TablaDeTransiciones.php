<?php

namespace App\Conversacion;

/**
 * Qué transiciones existen. **Lo que no está acá, no pasa.**
 *
 * El criterio de T-018 es que las transiciones no declaradas se rechacen, y esta
 * tabla es la declaración. Está separada de la máquina a propósito: se lee como
 * la matriz del catálogo de flujos, y una discrepancia entre el documento y el
 * código se ve comparando dos tablas y no leyendo un `switch`.
 *
 * ## Los comodines son el costo real
 *
 * Tres transiciones salen **desde cualquier estado**: la intervención humana, la
 * inactividad y el fallo de un tercero. En la matriz del catálogo ocupan una
 * flecha cada una, pero **son una transición por estado de origen** — y eso es
 * lo que lleva la superficie de ~6 a ~22, que fue la razón de partir T-018.
 */
class TablaDeTransiciones
{
    /**
     * Transiciones del camino feliz, declaradas una por una.
     *
     * @return array<string,array<string,Estado>>  origen => [evento => destino]
     */
    private static function explicitas(): array
    {
        return [
            Estado::Idle->value => [
                Transicion::MensajeInicial->value => Estado::GatheringParams,
            ],
            Estado::GatheringParams->value => [
                /*
                 * Las dos se quedan en `GATHERING_PARAMS`: la recolección de
                 * datos es un ciclo dentro del mismo estado, no una cadena de
                 * estados. Declararlas igual es lo que hace subir la versión y
                 * caducar los botones anteriores.
                 */
                Transicion::PideNombre->value => Estado::GatheringParams,
                Transicion::NombreRecibido->value => Estado::GatheringParams,
                /*
                 * Va directo a `SELECTING_SLOT`, sin pasar por
                 * `CALCULATING_QUOTE`: el cotizador está diferido (T-016, T-040,
                 * T-041). Cuando vuelva, se inserta acá.
                 */
                Transicion::ParametrosCompletos->value => Estado::SelectingSlot,
            ],
            Estado::SelectingSlot->value => [
                Transicion::SlotElegido->value => Estado::SlotSelected,
            ],
            Estado::SlotSelected->value => [
                Transicion::ReservaConfirmada->value => Estado::Booked,
            ],
            Estado::Booked->value => [
                Transicion::ConfirmaAsistencia->value => Estado::Confirmed,
                Transicion::Cancela->value => Estado::Cancelled,
                Transicion::PideReagendar->value => Estado::Rescheduled,
            ],
            Estado::Confirmed->value => [
                // Un turno confirmado todavía se puede cancelar.
                Transicion::Cancela->value => Estado::Cancelled,
                // Y también mover: confirmar no es comprometerse con la hora.
                Transicion::PideReagendar->value => Estado::Rescheduled,
            ],
            /*
             * T-042 · Se reusa `ParametrosCompletos` en vez de declarar un
             * evento propio: en un re-agendamiento los datos **ya están juntos**
             * —salen del turno que se está moviendo— que es literalmente lo que
             * ese evento significa. Reusarlo hace que `ofrecerHorarios()` sirva
             * sin cambios para los dos caminos.
             */
            Estado::Rescheduled->value => [
                Transicion::ParametrosCompletos->value => Estado::SelectingSlot,
            ],
            Estado::PausedHuman->value => [
                Transicion::TerminaPausa->value => Estado::Idle,
            ],
            Estado::ErrorFallback->value => [
                Transicion::FallbackEnviado->value => Estado::Idle,
            ],
        ];
    }

    /**
     * ¿A qué estado lleva este evento desde este estado? `null` si no existe.
     */
    public static function destino(Estado $desde, Transicion $evento): ?Estado
    {
        // Comodín 1 · La intervención humana llega desde cualquier estado que no
        // sea la pausa misma. Incluso desde un turno ya agendado: el cliente
        // puede escribir después de reservar y el operador contestarle.
        if ($evento === Transicion::IntervieneHumano) {
            return $desde === Estado::PausedHuman ? null : Estado::PausedHuman;
        }

        /*
         * Comodín 2 · El fallo de un tercero, desde cualquier estado con un flujo
         * a medias. Desde un estado terminal no aplica: si el turno ya está
         * agendado, que Google falle después no le cambia nada al cliente.
         */
        if ($evento === Transicion::FallaTercero) {
            return $desde->esTerminal() || $desde === Estado::ErrorFallback
                ? null
                : Estado::ErrorFallback;
        }

        /*
         * Comodín 3 · La inactividad expira **solo lo que está en curso**.
         * Expirar un `BOOKED` borraría el rastro de un turno real, y expirar un
         * `IDLE` no significa nada.
         */
        if ($evento === Transicion::Inactividad) {
            return $desde->estaEnCurso() ? Estado::Idle : null;
        }

        /*
         * Comodín 4 · T-021 · El reinicio llega desde cualquier estado **menos**
         * `IDLE`, donde ya no hay nada que reiniciar. AC-16.3 pide que limpie el
         * estado anterior, y eso lo hace la máquina vaciando el contexto.
         *
         * Incluye los estados terminales a propósito: alguien con un turno ya
         * agendado que escribe «menú» quiere empezar otra consulta, no que el
         * bot lo ignore.
         */
        if ($evento === Transicion::Reinicia) {
            return $desde === Estado::Idle ? null : Estado::Idle;
        }

        return self::explicitas()[$desde->value][$evento->value] ?? null;
    }

    /**
     * Eventos válidos desde un estado. Sirve para decirle al cliente qué se
     * espera de él cuando manda algo que no corresponde (AC-03.4).
     *
     * @return array<int,Transicion>
     */
    public static function eventosValidosDesde(Estado $desde): array
    {
        $validos = [];

        foreach (Transicion::cases() as $evento) {
            if (self::destino($desde, $evento) !== null) {
                $validos[] = $evento;
            }
        }

        return $validos;
    }
}
