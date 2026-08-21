<?php

namespace App\Conversacion;

/**
 * Los eventos que mueven la conversación de un estado a otro.
 *
 * Un evento **no** es un mensaje del cliente: es lo que el sistema concluyó del
 * mensaje. Separarlos permite que la máquina no sepa nada de WhatsApp — la
 * interpretación del texto o del botón ocurre antes, en T-019.
 */
enum Transicion: string
{
    /** Primer mensaje de alguien sin flujo activo. */
    case MensajeInicial = 'mensaje_inicial';

    /**
     * T-026 · El cliente pidió reservar: se le pregunta el nombre.
     *
     * Se queda en `GATHERING_PARAMS` —origen y destino son el mismo estado— pero
     * **es una transición igual**, no una escritura suelta: hace subir la versión
     * y eso caduca los botones de la bienvenida (AC-03.2). Sin ella, el botón
     * «Reservar» seguiría vigente y tocarlo dos veces preguntaría el nombre dos
     * veces.
     */
    case PideNombre = 'pide_nombre';

    /** T-026 · El cliente escribió su nombre. */
    case NombreRecibido = 'nombre_recibido';

    /** Ya se juntaron los datos que hacían falta, incluido el nombre. */
    case ParametrosCompletos = 'parametros_completos';

    /** El cliente eligió un horario de la lista. */
    case SlotElegido = 'slot_elegido';

    /** Google confirmó el horario y el evento quedó creado. */
    case ReservaConfirmada = 'reserva_confirmada';

    /** El cliente tocó «Confirmar» en el recordatorio t-24h. */
    case ConfirmaAsistencia = 'confirma_asistencia';

    /** El cliente tocó «Cancelar», o el evento se borró en Google. */
    case Cancela = 'cancela';

    /**
     * T-042 · El cliente tocó «Re-agendar» en el recordatorio t-24h.
     *
     * Lleva a `RESCHEDULED`, que **no toca el turno original**: de ahí sale
     * `ParametrosCompletos` hacia `SELECTING_SLOT`, porque en un
     * re-agendamiento los datos ya están juntos —vienen del turno que se está
     * moviendo— y volver a pedirlos es lo que *(AC-11.1)* prohíbe.
     */
    case PideReagendar = 'pide_reagendar';

    /**
     * El operador contestó a mano desde el celular. Comodín: llega **desde
     * cualquier estado**.
     */
    case IntervieneHumano = 'interviene_humano';

    /** Vencieron los 60 minutos de la pausa humana. */
    case TerminaPausa = 'termina_pausa';

    /**
     * Pasaron 30 minutos sin respuesta (T-007). Comodín desde cualquier estado
     * en curso.
     */
    case Inactividad = 'inactividad';

    /**
     * Falló un tercero. Comodín desde cualquier estado.
     *
     * Lleva a `ERROR_FALLBACK`, que **libera lo reservado** y devuelve a `IDLE`.
     */
    case FallaTercero = 'falla_tercero';

    /** El mensaje de cortesía ya salió: se vuelve al reposo. */
    case FallbackEnviado = 'fallback_enviado';

    /**
     * T-021 · El cliente escribió la palabra de reinicio.
     *
     * Comodín, **desde cualquier estado** (AC-16.3). Es la salida de emergencia
     * del cliente: si el bot se trabó o él se arrepintió, escribir «menú» lo
     * devuelve al principio sin depender de que nosotros hayamos previsto su
     * caso.
     */
    case Reinicia = 'reinicia';
}
