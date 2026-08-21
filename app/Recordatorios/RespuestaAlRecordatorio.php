<?php

namespace App\Recordatorios;

use App\Conversacion\Derivacion;
use App\Conversacion\MaquinaDeEstados;
use App\Conversacion\Transicion;
use App\Conversacion\Interactivo\IdSellado;
use App\Meta\MetaAdapter;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Services\Google\CalendarioDeGoogle;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\Log;

/**
 * T-037 · Qué pasa cuando el cliente toca un botón del recordatorio t-24h.
 *
 * ## La respuesta se resuelve por el `wamid`, no por el estado de la conversación
 *
 * Verificado contra Meta real: el webhook de un botón de plantilla llega con
 * `context.id` apuntando al mensaje que lo originó. Como `notification_logs`
 * guarda el `wamid` del recordatorio **por turno**, ese `context.id` alcanza
 * para saber a qué turno corresponde la respuesta sin preguntarle nada a la
 * conversación.
 *
 * Es el camino robusto y cubre los tres casos que el otro no: el cliente que
 * confirma tres días después, el que tiene dos turnos abiertos y el que
 * mientras tanto reinició la conversación.
 *
 * ⚠️ **Hay una tensión con AC-03.2 y se resuelve a favor de ejecutar la acción.**
 * El sello de T-019 existe para que no se ejecute la acción de *otro paso del
 * flujo*, y esta no es una acción de un paso del flujo: es la respuesta a un
 * mensaje que le mandamos nosotros, sobre un turno que sigue existiendo. El
 * sello sigue sirviendo para saber **qué** botón se tocó.
 */
class RespuestaAlRecordatorio
{
    /**
     * T-042 · El turno que el cliente pidió mover en el ultimo `manejar()`.
     *
     * Se expone en vez de devolverse por lo mismo que `Reserva::eventoHuerfano()`:
     * `manejar()` ya devuelve "lo resolvi yo", y un tipo de retorno que ademas
     * distinga "y encima ofrecele horarios" obligaria a todos los llamadores a
     * tratar un caso que casi nunca ocurre.
     */
    private ?Booking $reagendando = null;

    public function __construct(private readonly CalendarioDeGoogle $calendario) {}

    /** El turno que se esta moviendo, si el cliente toco Re-agendar. */
    public function reagendando(): ?Booking
    {
        return $this->reagendando;
    }

    /**
     * @param  array<string,mixed>  $mensaje  El mensaje crudo de Meta.
     * @return bool  `true` si era la respuesta a un recordatorio y se resolvió acá.
     */
    public function manejar(Integration $meta, Conversation $conversacion, array $mensaje, ?float $recibidoEn = null): bool
    {
        $this->reagendando = null;

        $registro = $this->recordatorioQueLoOrigino($mensaje);

        if ($registro === null) {
            return false;
        }

        $booking = Booking::query()->find($registro->booking_id);

        if ($booking === null) {
            // La fila del recordatorio existe y el turno no: no hay nada que
            // confirmar ni que cancelar, pero tampoco puede pasar en silencio.
            Log::error('Respuesta a un recordatorio de un turno que ya no existe', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'notification_log_id' => $registro->id,
                'codigo' => 'RECORDATORIO_TURNO_AUSENTE',
            ]);

            return false;
        }

        $accion = IdSellado::leer($mensaje['button']['payload'] ?? null)?->accion;
        $adapter = new MetaAdapter($meta);

        return match ($accion) {
            PlantillaRecordatorio24h::CONFIRMAR => $this->confirmar($adapter, $conversacion, $booking, $recibidoEn),
            PlantillaRecordatorio24h::CANCELAR => $this->cancelar($adapter, $conversacion, $booking, $recibidoEn),
            PlantillaRecordatorio24h::REAGENDAR => $this->reagendar($adapter, $conversacion, $booking, $recibidoEn),
            default => $this->noSeEntendio($conversacion, $booking, $accion),
        };
    }

    /**
     * El recordatorio al que responde este mensaje, si responde a alguno.
     *
     * @param  array<string,mixed>  $mensaje
     */
    private function recordatorioQueLoOrigino(array $mensaje): ?NotificationLog
    {
        $contextId = $mensaje['context']['id'] ?? null;

        if (! is_string($contextId) || $contextId === '') {
            return null;
        }

        // Con el Global Scope activo: el `wamid` de una PyME no puede resolver a
        // un turno de otra (RNF-01).
        return NotificationLog::query()
            ->where('whatsapp_message_id', $contextId)
            ->where('type', NotificationLog::TIPO_RECORDATORIO_24H)
            ->first();
    }

    /**
     * AC-10.2 · El turno pasa a `confirmed` y el cliente recibe acuse.
     *
     * **El estado es el dato que sostiene el número del pitch**: sin él no hay
     * forma de distinguir al que confirmó del que nunca contestó, y sin esa
     * distinción no se puede medir si el ausentismo bajó.
     */
    private function confirmar(MetaAdapter $adapter, Conversation $conversacion, Booking $booking, ?float $recibidoEn): bool
    {
        $booking->forceFill(['status' => Booking::ESTADO_CONFIRMADO])->save();

        Log::info('El cliente confirmó su turno desde el recordatorio', [
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'codigo' => 'RECORDATORIO_CONFIRMADO',
        ]);

        /*
         * Un botón que se toca y no contesta nada se lee como que no funcionó: el
         * cliente vuelve a tocarlo, o llama por teléfono, que es justo lo que
         * esta historia viene a evitar.
         */
        $adapter->enviarTexto(
            $conversacion,
            "¡Gracias, {$booking->client_name}! Tu turno del "
            .$this->cuando($booking).' quedó confirmado. Te esperamos.',
            $recibidoEn,
        );

        return true;
    }

    /**
     * AC-10.3 · Se borra el evento, el horario se libera y llega la confirmación.
     *
     * ⚠️ **El `bookings.status` también pasa a `cancelled`, y es interpretación.**
     * El criterio dice «el horario vuelve a estar disponible» y eso, estrictamente,
     * lo cumple borrar el evento —la disponibilidad se calcula con `freeBusy`—.
     * Pero un `bookings` que siga diciendo `scheduled` deja el turno vivo para el
     * panel, para el registro de asistencia (T-036) y para el aviso t-2h (T-043),
     * que le escribiría a alguien que ya canceló.
     */
    private function cancelar(MetaAdapter $adapter, Conversation $conversacion, Booking $booking, ?float $recibidoEn): bool
    {
        $this->borrarElEvento($booking);

        $booking->forceFill(['status' => Booking::ESTADO_CANCELADO])->save();

        Log::info('El cliente canceló su turno desde el recordatorio', [
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'codigo' => 'RECORDATORIO_CANCELADO',
        ]);

        $adapter->enviarTexto(
            $conversacion,
            'Listo, cancelamos tu turno del '.$this->cuando($booking).'. '
            .'Cuando quieras sacar otro, escribinos.',
            $recibidoEn,
        );

        return true;
    }

    /**
     * T-042 · AC-11.1 · El cliente pidió mover su turno: vuelve a la lista de
     * horarios **sin que se le pida un solo dato de nuevo**.
     *
     * ⚠️ **El turno original no se toca acá.** El ticket lo exige dos veces —«el
     * turno nuevo se crea antes de borrar el viejo» y *(AC-11.3)* «pasados 30
     * minutos el turno original sigue vigente»— y contradice al catálogo de
     * flujos, que describe `RESCHEDULED` como «cita previa anulada». Anular
     * primero produce el peor caso: el cliente pide mover el turno, no hay otro
     * horario que le sirva, y se queda sin ninguno habiendo tenido uno.
     *
     * El nombre y el servicio se copian del turno al contexto porque el turno es
     * la fuente de verdad de esos datos: la conversación pudo haberse reiniciado
     * entre que se reservó y que llegó el recordatorio, y en ese caso su contexto
     * está vacío.
     *
     * Quién ofrece los horarios es el job y no este método: acá no hay acceso al
     * consultor de disponibilidad ni al renderizador, y traerlos convertiría al
     * manejador de respuestas del recordatorio en una segunda copia del flujo.
     * Se expone el turno que se está moviendo, igual que `Reserva` expone el
     * evento huérfano.
     */
    private function reagendar(MetaAdapter $adapter, Conversation $conversacion, Booking $booking, ?float $recibidoEn): bool
    {
        app(MaquinaDeEstados::class)->aplicar($conversacion, Transicion::PideReagendar, [
            'nombre' => $booking->client_name,
            'servicio' => $booking->service_name,
            // El turno que se está moviendo. Sin esto, al confirmar el horario
            // nuevo nadie sabe cuál era el viejo y quedarian los dos vivos.
            'reagendando_booking_id' => $booking->id,
        ]);

        Log::info('El cliente pidió re-agendar desde el recordatorio', [
            'tenant_id' => $booking->tenant_id,
            'conversation_id' => $conversacion->id,
            'booking_id' => $booking->id,
            'codigo' => 'RECORDATORIO_REAGENDA_PEDIDA',
        ]);

        $adapter->enviarTexto(
            $conversacion,
            'Sin problema, '.$booking->client_name.'. Tu turno del '.$this->cuando($booking)
            .' sigue reservado hasta que elijas otro. Estos son los horarios que tengo:',
            $recibidoEn,
        );

        $this->reagendando = $booking;

        return true;
    }

    /**
     * Vino con `context.id` de un recordatorio nuestro pero sin acción legible.
     *
     * Pasa si Meta cambia la forma del payload o si el cliente responde con texto
     * citando el recordatorio. **No se ejecuta nada** —adivinar entre confirmar y
     * cancelar es exactamente lo que no se puede hacer— y se devuelve `false`
     * para que el flujo normal lo trate como cualquier otro mensaje.
     */
    private function noSeEntendio(Conversation $conversacion, Booking $booking, ?string $accion): bool
    {
        Log::info('Respuesta a un recordatorio sin acción reconocible', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'booking_id' => $booking->id,
            'accion' => $accion,
            'codigo' => 'RECORDATORIO_ACCION_DESCONOCIDA',
        ]);

        return false;
    }

    /**
     * Borra el evento del calendario del negocio. **Es lo que libera la franja
     * de verdad**: mientras el evento exista, `freeBusy` sigue viendo el horario
     * ocupado para todos los demás.
     */
    private function borrarElEvento(Booking $booking): void
    {
        $eventId = trim((string) $booking->external_event_id);
        $google = Integration::query()->find($booking->integration_id);

        if ($eventId === '' || $google === null) {
            Log::error('Turno cancelado sin evento que borrar', [
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'codigo' => 'CANCELACION_SIN_EVENTO',
            ]);

            return;
        }

        try {
            $borrado = $this->calendario->borrarEvento($google, $eventId);
        } catch (\Throwable $e) {
            $borrado = false;
        }

        if (! $borrado) {
            /*
             * El turno queda cancelado igual —el cliente avisó que no va— pero el
             * horario sigue bloqueado en el calendario del negocio. Es H-04, y no
             * puede pasar sin dejar rastro: es lo que T-030 va a tener que
             * conciliar.
             */
            Log::error('No se pudo borrar el evento del turno cancelado', [
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'external_event_id' => $eventId,
                'integracion' => 'google_calendar',
                'codigo' => 'CANCELACION_EVENTO_NO_BORRADO',
            ]);
        }
    }

    /** AC-07.4 · La hora en la zona del tenant, nunca en UTC. */
    private function cuando(Booking $booking): string
    {
        $tenant = Tenant::find($booking->tenant_id);

        return $tenant !== null
            ? HoraLocal::completa($booking->start_time, $tenant)
            : '';
    }
}
