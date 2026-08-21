<?php

namespace App\Conversacion;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Services\Google\CalendarioDeGoogle;
use Illuminate\Support\Facades\Log;

/**
 * T-042 · Cerrar un re-agendamiento: el turno nuevo ya existe, falta soltar el
 * viejo.
 *
 * ## El orden no es un detalle de implementación, es el criterio
 *
 * El ticket lo pide textual: *«El turno nuevo se crea antes de borrar el viejo:
 * no hay ventana en la que el cliente se quede sin ninguno»*. Por eso esto corre
 * **después** de `Reserva::agendar()` y no antes: si se borrara primero y Google
 * fallara al crear el nuevo, el cliente que pidió mover su turno se quedaría sin
 * ninguno — que es peor que no haberlo dejado mover.
 *
 * ⚠️ Y por eso mismo **contradice al catálogo de flujos**, que describe
 * `RESCHEDULED` como *«cita previa anulada»*. La contradicción está anotada en
 * `Estado::Rescheduled`; el catálogo hay que corregirlo.
 *
 * ## Qué pasa si el borrado falla
 *
 * El turno nuevo queda igual. Perder el evento viejo en el calendario es H-04
 * —una franja bloqueada por un turno que ya no existe— y se registra para que lo
 * levante la conciliación de T-030, exactamente como hace la cancelación. Lo que
 * **no** se hace es deshacer el turno nuevo: el cliente ya recibió, o está por
 * recibir, su confirmación.
 */
class Reagendamiento
{
    /** Clave del contexto de la conversación que nombra al turno que se mueve. */
    public const CLAVE = 'reagendando_booking_id';

    public function __construct(private readonly CalendarioDeGoogle $calendario) {}

    /**
     * ¿Esta conversación está moviendo un turno? Devuelve el turno original.
     *
     * ⚠️ Se filtra por `tenant_id` además del Global Scope y se exige que la
     * fila siga viva: un `reagendando_booking_id` que quedó pegado en el
     * contexto de una conversación vieja no puede hacer que una reserva nueva
     * borre un turno que nadie pidió mover.
     */
    public function turnoQueSeMueve(Conversation $conversacion): ?Booking
    {
        $id = $conversacion->context_data[self::CLAVE] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        return Booking::query()
            ->where('tenant_id', $conversacion->tenant_id)
            ->where('id', $id)
            ->whereIn('status', [Booking::ESTADO_AGENDADO, Booking::ESTADO_CONFIRMADO])
            ->first();
    }

    /**
     * Suelta el turno viejo ahora que el nuevo ya está creado y persistido.
     *
     * @param  Booking  $viejo  El turno que se movió.
     * @param  Booking  $nuevo  El que acaba de crearse. Solo se usa para el registro.
     */
    public function soltarElViejo(Booking $viejo, Booking $nuevo, Conversation $conversacion): void
    {
        $this->borrarElEvento($viejo);

        /*
         * `rescheduled` y no `cancelled`: el ENUM ya lo tiene y la diferencia es
         * medible. Un cliente que canceló es un turno perdido; uno que movió es
         * un turno conservado, y el argumento de venta los cuenta distinto
         * (AC-17.4).
         */
        $viejo->forceFill(['status' => Booking::ESTADO_REAGENDADO])->save();

        Log::info('Turno re-agendado', [
            'tenant_id' => $viejo->tenant_id,
            'booking_id' => $viejo->id,
            'booking_nuevo_id' => $nuevo->id,
            'conversation_id' => $conversacion->id,
            'codigo' => 'TURNO_REAGENDADO',
        ]);

        $this->olvidar($conversacion);
    }

    /**
     * Saca la marca del contexto.
     *
     * Sin esto, la reserva **siguiente** de este mismo cliente volvería a leer
     * el `reagendando_booking_id` y borraría un turno que él no pidió mover.
     */
    public function olvidar(Conversation $conversacion): void
    {
        $contexto = $conversacion->context_data ?? [];

        unset($contexto[self::CLAVE]);

        $conversacion->forceFill(['context_data' => $contexto])->save();
    }

    /**
     * Borra el evento del turno viejo. **Es lo que libera la franja de verdad**:
     * mientras el evento exista, `freeBusy` lo sigue viendo ocupado.
     */
    private function borrarElEvento(Booking $viejo): void
    {
        $eventId = trim((string) $viejo->external_event_id);
        $google = Integration::query()->find($viejo->integration_id);

        if ($eventId === '' || $google === null) {
            Log::error('Turno re-agendado sin evento que borrar', [
                'tenant_id' => $viejo->tenant_id,
                'booking_id' => $viejo->id,
                'codigo' => 'REAGENDA_SIN_EVENTO',
            ]);

            return;
        }

        try {
            $borrado = $this->calendario->borrarEvento($google, $eventId);
        } catch (\Throwable) {
            $borrado = false;
        }

        if (! $borrado) {
            /*
             * El turno nuevo queda igual: el cliente ya tiene su horario. Lo que
             * queda mal es el calendario de la PyME, con la franja vieja
             * bloqueada por un turno que ya no existe — H-04, que es lo que la
             * conciliación de T-030 levanta.
             */
            Log::error('No se pudo borrar el evento del turno re-agendado', [
                'tenant_id' => $viejo->tenant_id,
                'booking_id' => $viejo->id,
                'external_event_id' => $eventId,
                'integracion' => 'google_calendar',
                'codigo' => 'REAGENDA_EVENTO_NO_BORRADO',
            ]);
        }
    }
}
