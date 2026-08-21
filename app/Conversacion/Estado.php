<?php

namespace App\Conversacion;

/**
 * Los estados de la conversación — **diez**, no once.
 *
 * El catálogo de `03-flujos/05-estados-flujo-conversacional.md` lista once. Sigue
 * faltando uno: `CALCULATING_QUOTE` cae con el cotizador (T-016, T-040 y T-041
 * diferidos), y **no se declara acá a propósito** — un estado al que ninguna
 * transición llega deja una rama muerta que parece implementada.
 *
 * `RESCHEDULED` volvió con T-042, con sus transiciones en el mismo cambio.
 */
enum Estado: string
{
    /** Reposo. No hay flujo activo. */
    case Idle = 'IDLE';

    /**
     * Recolección de parámetros. **Acá se captura el nombre** (decisión cerrada
     * en T-007): pedirlo recién al elegir horario dejaría sin nombre a todo lead
     * que abandona antes, que es justo el que US-13 existe para capturar.
     */
    case GatheringParams = 'GATHERING_PARAMS';

    /** Se desplegó la lista de horarios libres que devolvió T-022. */
    case SelectingSlot = 'SELECTING_SLOT';

    /** El cliente eligió un horario; falta confirmarlo contra Google. */
    case SlotSelected = 'SLOT_SELECTED';

    /** Turno creado en Google Calendar y en la base. */
    case Booked = 'BOOKED';

    /** El cliente tocó «Confirmar» en el recordatorio t-24h. */
    case Confirmed = 'CONFIRMED';

    /**
     * T-042 · El cliente pidió mover su turno y todavía no eligió el nuevo.
     *
     * ⚠️ **El catálogo de flujos lo describe como «cita previa anulada» y eso es
     * lo contrario de lo que exige el ticket.** Acá el turno original sigue
     * vivo: solo se borra cuando el nuevo ya existe. Anular primero deja sin
     * turno al cliente que pidió moverlo y no encuentra otro horario que le
     * sirva — y *(AC-11.3)* pide explícitamente que a los 30 minutos de abandono
     * el original siga vigente.
     */
    case Rescheduled = 'RESCHEDULED';

    /** Turno anulado, por el cliente o desde Google Calendar. */
    case Cancelled = 'CANCELLED';

    /** El operador contestó a mano: el bot se calla. */
    case PausedHuman = 'PAUSED_HUMAN';

    /**
     * Falló un tercero. Se envió el mensaje de cortesía, **se liberó lo
     * reservado a medias** y la conversación vuelve a `Idle` (T-007).
     */
    case ErrorFallback = 'ERROR_FALLBACK';

    /**
     * ¿Es un estado del que ya no se sigue conversando?
     *
     * Importa para la expiración por inactividad: no tiene sentido "expirar"
     * una conversación que ya terminó en un turno agendado.
     */
    public function esTerminal(): bool
    {
        return match ($this) {
            self::Booked, self::Confirmed, self::Cancelled => true,
            default => false,
        };
    }

    /** ¿Hay un flujo a medias que perder si la conversación expira? */
    public function estaEnCurso(): bool
    {
        return match ($this) {
            self::GatheringParams, self::SelectingSlot, self::SlotSelected, self::Rescheduled => true,
            default => false,
        };
    }
}
