<?php

namespace App\Conversacion;

use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Google\CalendarioDeGoogle;
use App\Services\Google\ClaveDeIdempotencia;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * T-026 · Convierte un horario elegido en un turno real.
 *
 * ## El orden de las tres operaciones es el ticket
 *
 * 1. **Crear el evento en Google.** Si falla, no hay turno y no se persiste nada.
 * 2. **Persistir la fila en `bookings`** con el `external_event_id`.
 * 3. **Recién ahí, confirmarle al cliente.**
 *
 * El criterio *"la confirmación se envía después de que la fila esté
 * persistida"* no es una preferencia de estilo: si se confirmara antes y el
 * insert fallara, el cliente tendría un turno que el sistema no conoce — no
 * genera recordatorio, no se puede cancelar desde el chat, no aparece en el
 * panel, **y bloquea el horario para todos los demás**. Es exactamente el modo
 * de falla que describe T-030.
 *
 * ⚠️ **La ventana entre el paso 1 y el 2 sigue abierta.** Si el evento se crea y
 * el insert falla, queda un evento huérfano en Google. Cerrarla —compensar o
 * conciliar— es **T-030**, y su decisión sigue abierta. Acá se registra con un
 * código propio para que ese ticket tenga qué leer.
 */
class Reserva
{
    /**
     * El evento que quedó creado sin su fila, si lo hubo.
     *
     * Se expone en vez de devolverse porque `agendar()` ya devuelve el turno, y
     * un tipo de retorno que sea "turno o id de evento huérfano" obligaría a
     * todos los llamadores a distinguir dos casos que casi nunca ocurren.
     */
    private ?string $eventoHuerfano = null;

    public function __construct(private readonly CalendarioDeGoogle $calendario) {}

    /** El `id` del evento que quedó huérfano en el último `agendar()`. */
    public function eventoHuerfano(): ?string
    {
        return $this->eventoHuerfano;
    }

    /**
     * @return \App\Models\Booking|null  `null` si no se pudo agendar.
     */
    public function agendar(
        Conversation $conversacion,
        Tenant $tenant,
        BusinessSetting $config,
        Integration $google,
        CarbonImmutable $inicio,
    ): ?\App\Models\Booking {
        $this->eventoHuerfano = null;

        $fin = $inicio->addMinutes($config->slot_duration_minutes);

        $nombre = $this->nombreDelCliente($conversacion);
        $servicio = $conversacion->context_data['servicio'] ?? 'Turno';

        // 1 · Google primero. Sin evento no hay turno.
        $eventId = $this->calendario->crearEvento(
            integration: $google,
            tenant: $tenant,
            inicio: $inicio,
            fin: $fin,
            nombreCliente: $nombre,
            telefono: $conversacion->user_phone,
            servicio: $servicio,
            // ⚠️ Sin monto: el cotizador está diferido (T-016, T-040, T-041).
            monto: null,
            /*
             * T-030 · AC-22.3 · La clave se manda **siempre**, no solo en el
             * reintento: el código que agenda no sabe si es la primera vez. Es
             * el `id` del evento, y hace que el segundo intento reciba `409` en
             * vez de crear un turno duplicado sobre el mismo horario.
             */
            idempotencia: ClaveDeIdempotencia::paraTurno($tenant->id, $conversacion->id, $inicio),
        );

        if ($eventId === null) {
            return null;
        }

        // 2 · La fila, con el id del evento.
        try {
            $booking = \App\Models\Booking::create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversacion->id,
                'integration_id' => $google->id,
                'external_event_id' => $eventId,
                'client_name' => $nombre,
                'client_phone' => $conversacion->user_phone,
                'service_name' => $servicio,
                'quote_amount' => null,
                // El cast `FechaUtc` garantiza que se guarde en UTC (AC-07.1).
                'start_time' => $inicio,
                'end_time' => $fin,
                'status' => \App\Models\Booking::ESTADO_AGENDADO,
                'attendance' => \App\Models\Booking::ASISTENCIA_PENDIENTE,
            ]);
        } catch (\Throwable $e) {
            /*
             * T-030 · AC-22.3 · Antes de dar el turno por perdido: puede que la
             * fila no se haya podido crear **porque ya estaba**. El único
             * `(tenant_id, external_event_id)` es el que rechaza la segunda, y
             * es el candado real —un `SELECT` previo deja abierta la ventana
             * entre dos workers que miran a la vez—.
             */
            $yaExistia = $this->turnoYaPersistido($tenant, $eventId);

            if ($yaExistia !== null) {
                Log::info('El reintento convergió al turno que ya existía', [
                    'tenant_id' => $tenant->id,
                    'booking_id' => $yaExistia->id,
                    'external_event_id' => $eventId,
                    'codigo' => 'RESERVA_IDEMPOTENTE',
                ]);

                return $yaExistia;
            }

            /*
             * El evento existe y la fila no. **Se recuerda el id** para que
             * T-018c pueda borrarlo: sin eso, el horario queda bloqueado en el
             * calendario de la PyME por un turno que nunca existió — el hueco
             * H-04.
             */
            $this->eventoHuerfano = $eventId;

            Log::error('Evento creado en Google pero la reserva no se persistió', [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversacion->id,
                'external_event_id' => $eventId,
                'codigo' => 'RESERVA_INCONSISTENTE',
                'excepcion' => $e::class,
            ]);

            return null;
        }

        Log::info('Turno agendado', [
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'external_event_id' => $eventId,
            'codigo' => 'RESERVA_CREADA',
        ]);

        return $booking;
    }

    /**
     * ¿Ya hay una fila para este evento **de este tenant**?
     *
     * El filtro por `tenant_id` es explícito y no solo del Global Scope: Google
     * no garantiza que dos cuentas distintas no repitan un `id`, y devolverle a
     * una PyME el turno de otra sería la fuga que RNF-01 existe para impedir.
     *
     * Si la consulta también falla —la base está caída, que es el caso en que el
     * `INSERT` falló por eso mismo— se devuelve `null` y el turno sigue el camino
     * del huérfano. No saber no es lo mismo que saber que no está.
     */
    private function turnoYaPersistido(Tenant $tenant, string $eventId): ?\App\Models\Booking
    {
        try {
            return \App\Models\Booking::withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('external_event_id', $eventId)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * El texto de confirmación, con la hora **en la zona del tenant**.
     *
     * Mandarla en UTC sería el error que RNF-02 existe para evitar: el cliente
     * leería una hora que no es la de su turno y llegaría tres horas tarde.
     */
    public function textoDeConfirmacion(\App\Models\Booking $booking, Tenant $tenant): string
    {
        $cuando = HoraLocal::completa($booking->start_time, $tenant);

        return "¡Listo, {$booking->client_name}! Tu turno quedó reservado.\n\n"
            ."📅 {$cuando}\n"
            ."📍 {$tenant->name}\n\n"
            .'Te vamos a escribir un día antes para recordártelo.';
    }

    /**
     * El nombre que dio el cliente.
     *
     * Se captura en `GATHERING_PARAMS` (decisión cerrada en T-007): pedirlo
     * recién al elegir horario dejaría sin nombre a todo lead que abandona
     * antes, que es el que US-13 existe para capturar.
     */
    private function nombreDelCliente(Conversation $conversacion): string
    {
        $nombre = trim((string) ($conversacion->context_data['nombre'] ?? ''));

        // No debería pasar: el flujo pide el nombre antes de ofrecer horarios.
        // Si pasa, se agenda igual — un turno sin nombre es mejor que ninguno.
        return $nombre !== '' ? $nombre : 'Cliente de WhatsApp';
    }
}
