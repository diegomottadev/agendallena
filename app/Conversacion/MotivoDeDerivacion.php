<?php

namespace App\Conversacion;

/**
 * T-035 · Por qué se derivó la conversación a una persona (AC-20.1).
 *
 * Son los tres que nombra US-20, y existen como enum —y no como string suelto—
 * porque el motivo es el dato que la historia dice que vinimos a aprender:
 * *"cuál de los tres motivos deriva más"*. Con strings libres, contarlos sería
 * contar erratas.
 */
enum MotivoDeDerivacion: string
{
    /**
     * El cotizador devolvió un valor fuera de rango (AC-12.4).
     *
     * ⚠️ **Declarado sin disparador.** El cotizador está diferido (T-016, T-040
     * y T-041), así que hoy nada produce este motivo. Se declara igual porque el
     * enum es el contrato del panel y de los conteos: cuando el cotizador
     * vuelva, agrega la llamada y no toca ni el panel ni la tabla.
     */
    case OutOfRange = 'out_of_range';

    /** La agenda estaba llena y el cliente pidió hablar con alguien (T-024). */
    case NoAvailability = 'no_availability';

    /** El bot no entendió lo que le escribieron, N veces seguidas. */
    case UnknownInput = 'unknown_input';

    /** Cómo se lee en el panel. En castellano, que es lo que ve la PyME. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::OutOfRange => 'Consulta fuera de rango',
            self::NoAvailability => 'No había turnos disponibles',
            self::UnknownInput => 'El bot no entendió',
        };
    }
}
