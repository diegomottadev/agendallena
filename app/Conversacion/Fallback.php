<?php

namespace App\Conversacion;

use App\Meta\MetaAdapter;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Integration;
use App\Services\Google\CalendarioDeGoogle;
use Illuminate\Support\Facades\Log;

/**
 * T-018c · `ERROR_FALLBACK`: qué pasa cuando falla un tercero.
 *
 * La decisión de T-007 en una línea: **libera lo reservado y vuelve a `IDLE`.**
 *
 * ## Qué significa "liberar" según dónde estaba la conversación
 *
 * No es lo mismo en cada punto del flujo, y confundirlos produce dos errores
 * opuestos —dejar basura, o borrar un turno real:
 *
 * | Estado al fallar | Qué hay que liberar |
 * | :-- | :-- |
 * | `GATHERING_PARAMS`, `SELECTING_SLOT` | Nada material. Solo el contexto |
 * | `SLOT_SELECTED` | El horario elegido, y **el evento de Google si quedó huérfano** |
 * | `BOOKED`, `CONFIRMED` | **Nada.** El turno existe y es válido |
 *
 * La última fila es la que importa marcar: si Google falla *después* de que el
 * turno quedó agendado, borrarlo sería destruir algo real por un problema que
 * ya pasó. Por eso la tabla de transiciones no admite `FallaTercero` desde un
 * estado terminal.
 *
 * ## La compensación es oportunista, no sistemática
 *
 * Si el evento se creó en Google y el `INSERT` falló, acá **se borra el evento**
 * —tenemos su `id` en la mano y el proceso sigue vivo—. Pero si el worker muere
 * entre las dos operaciones, el huérfano queda igual.
 *
 * ⚠️ **Cerrar esa ventana del todo es T-030**, y su decisión —*compensar o
 * conciliar*— sigue abierta. Esto no la reemplaza: le saca los casos fáciles de
 * encima para que la conciliación tenga menos que limpiar.
 */
class Fallback
{
    public function __construct(
        private readonly MaquinaDeEstados $maquina,
        private readonly CalendarioDeGoogle $calendario,
    ) {}

    /**
     * Lleva la conversación a `ERROR_FALLBACK`, avisa al cliente y vuelve a `IDLE`.
     *
     * @param  string|null  $eventoHuerfano  `id` de un evento de Google que quedó
     *   creado sin su fila. Si viene, se borra.
     */
    public function manejar(
        Conversation $conversacion,
        Integration $meta,
        ?BusinessSetting $config,
        ?float $recibidoEn = null,
        ?string $eventoHuerfano = null,
        ?Integration $google = null,
    ): void {
        $estadoOriginal = Estado::from($conversacion->current_state);

        /*
         * Un turno ya agendado no se toca. La tabla de transiciones lo rechaza,
         * pero se comprueba acá también: llegar hasta el borrado del evento y
         * confiar en que la transición falle antes sería confiar en un orden que
         * un refactor puede invertir.
         */
        if ($estadoOriginal->esTerminal()) {
            Log::info('Fallo de un tercero sobre un turno ya agendado: no se toca', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'estado' => $estadoOriginal->value,
                'codigo' => 'FALLBACK_IGNORADO',
            ]);

            return;
        }

        // 1 · Liberar lo que haya quedado a medias en Google.
        if ($eventoHuerfano !== null && $google !== null) {
            $this->liberarEvento($conversacion, $google, $eventoHuerfano);
        }

        // 2 · La conversación entra en fallback.
        $this->maquina->aplicar($conversacion, Transicion::FallaTercero);

        // 3 · RNF-03 · El cliente recibe el mensaje de cortesía. Nunca silencio.
        $this->avisarAlCliente($conversacion, $meta, $config, $recibidoEn);

        /*
         * 4 · Vuelta a `IDLE` **limpiando el contexto**: el horario que el
         * cliente estaba eligiendo ya no vale, y arrastrarlo haría que la
         * conversación siguiente arranque con supuestos de una que se rompió.
         */
        $this->maquina->aplicar($conversacion->fresh(), Transicion::FallbackEnviado, limpiarContexto: true);

        Log::info('Conversación recuperada tras un fallo de un tercero', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'estado_previo' => $estadoOriginal->value,
            'evento_liberado' => $eventoHuerfano,
            'codigo' => 'FALLBACK_RESUELTO',
        ]);
    }

    /**
     * T-033 · AC-09.4 · Último recurso: el job agotó sus reintentos.
     *
     * `manejar()` cubre el fallo que el flujo **supo ver** —Google devolvió un
     * error que alguien atrapó, la reserva no se pudo persistir—. Esto cubre el
     * otro: la excepción que nadie atrapó y que tumbó el job tres veces.
     *
     * Son dos caminos y no uno porque las garantías son distintas:
     *
     * | | `manejar()` | `porFalloDefinitivo()` |
     * | :-- | :-- | :-- |
     * | Quién lo llama | El flujo, sabiendo qué falló | `ProcessMessageJob::failed()` |
     * | Puede lanzar | **Sí** — así la cola reintenta | **No.** Ya no hay reintento |
     * | Estado terminal | No se toca ni se avisa | No se toca, **pero se avisa** |
     *
     * La última fila es la diferencia que importa. Con un turno ya agendado,
     * `manejar()` se calla porque el fallo no le cambió nada al cliente. Acá el
     * job murió: el cliente quedó esperando una confirmación que nunca salió, y
     * callarse ahí **es exactamente el silencio que AC-09.4 prohíbe**. Avisar no
     * contradice a T-018c: *no tocar* el turno es no borrarlo ni moverlo de
     * estado, no dejar al cliente sin respuesta.
     *
     * ⚠️ **Punto de extensión:** el ticket deja sin definir si el fallback
     * escala activamente a un operador o solo informa al cliente —la misma
     * decisión que T-035 y T-039—. Acá **solo se informa**. Cuando se resuelva,
     * el escalamiento se engancha después de `avisarAlCliente()`: es el único
     * lugar del worker que sabe que un mensaje se perdió del todo.
     *
     * @param  string  $causa  Clase de la excepción que tumbó el job.
     */
    public function porFalloDefinitivo(
        Conversation $conversacion,
        Integration $meta,
        ?BusinessSetting $config,
        ?string $causa = null,
    ): void {
        Log::error('El worker agotó sus reintentos: se avisa al cliente y se cierra el flujo', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'estado' => $conversacion->current_state,
            'integracion' => 'meta_whatsapp',
            'codigo' => 'WORKER_FALLO_DEFINITIVO',
            'causa' => $causa,
        ]);

        /*
         * El aviso va **primero**. Si la transición fallara —la tabla no la
         * declara desde este estado, u otro worker tiene el lock—, el cliente ya
         * recibió su mensaje: la recuperación del estado es nuestra higiene, no
         * su problema.
         */
        $this->avisarAlCliente($conversacion, $meta, $config, tolerante: true);

        try {
            $estado = Estado::from($conversacion->current_state);

            // Un turno agendado no se mueve de estado. Solo se limpia lo que
            // quedó a medias.
            if ($estado->esTerminal() || $estado === Estado::Idle) {
                return;
            }

            /*
             * Ya estaba en `ERROR_FALLBACK`: el aviso salió y lo único que falta
             * es cerrar. Aplicar `FallaTercero` de nuevo lo rechazaría la tabla
             * —correctamente— y dejaría la conversación trabada ahí para siempre.
             */
            if ($estado === Estado::ErrorFallback) {
                $this->maquina->aplicar($conversacion, Transicion::FallbackEnviado, limpiarContexto: true);

                return;
            }

            $this->maquina->aplicar($conversacion, Transicion::FallaTercero);
            $this->maquina->aplicar($conversacion->fresh(), Transicion::FallbackEnviado, limpiarContexto: true);
        } catch (\Throwable $e) {
            /*
             * `failed()` no puede lanzar: si lo hiciera, Laravel registraría el
             * fallo del manejador de fallos y perdería el original, que es el
             * que explica qué pasó.
             */
            Log::warning('No se pudo devolver la conversación a IDLE tras el fallo definitivo', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'codigo' => 'FALLBACK_ESTADO_NO_RECUPERADO',
                'excepcion' => $e::class,
            ]);
        }
    }

    /**
     * RNF-03 · El texto de cortesía **del tenant** (T-017), nunca uno hardcodeado.
     *
     * `$tolerante` cambia qué pasa si el envío también falla, y las dos ramas
     * son deliberadas:
     *
     * - **`false`** (camino normal): se propaga. Un rate limit de Meta acá tiene
     *   que llegar hasta la cola para que reintente; tragarlo dejaría al cliente
     *   mudo justo en el mensaje que existe para que no lo esté.
     * - **`true`** (último recurso): se traga y se registra. Ya no queda
     *   reintento, y lanzar desde `failed()` solo perdería la excepción original.
     */
    private function avisarAlCliente(
        Conversation $conversacion,
        Integration $meta,
        ?BusinessSetting $config,
        ?float $recibidoEn = null,
        bool $tolerante = false,
    ): void {
        $texto = $config?->fallback_message
            ?? BusinessSetting::valoresPorDefecto()['fallback_message'];

        if (! $tolerante) {
            (new MetaAdapter($meta))->enviarTexto($conversacion, $texto, $recibidoEn);

            return;
        }

        try {
            $wamid = (new MetaAdapter($meta))->enviarTexto($conversacion, $texto, $recibidoEn);
        } catch (\Throwable $e) {
            $wamid = null;
        }

        if ($wamid !== null) {
            return;
        }

        /*
         * ⚠️ Acá **el chat sí se quedó mudo**, y no hay nada más que intentar:
         * el canal por el que le hablamos al cliente es el que está caído. Se
         * registra con un código propio en vez de confundirlo con el resto:
         * es el único caso en que AC-09.4 no se puede cumplir, y tiene que ser
         * visible como tal en el log que T-039 va a leer.
         */
        Log::error('No se pudo avisarle al cliente: el chat quedó sin respuesta', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'integracion' => 'meta_whatsapp',
            'codigo' => 'CLIENTE_SIN_AVISO',
        ]);
    }

    /**
     * Borra el evento que quedó sin su fila.
     *
     * Si el borrado falla, **no se propaga**: el cliente ya tiene bastante con
     * que su turno no se haya creado, y no puede hacer nada con nuestro problema
     * de consistencia. Se registra con un código que T-030 puede leer.
     */
    private function liberarEvento(Conversation $conversacion, Integration $google, string $eventId): void
    {
        try {
            $borrado = $this->calendario->borrarEvento($google, $eventId);
        } catch (\Throwable $e) {
            $borrado = false;
        }

        if ($borrado) {
            Log::info('Evento huérfano liberado del calendario', [
                'tenant_id' => $conversacion->tenant_id,
                'external_event_id' => $eventId,
                'codigo' => 'EVENTO_LIBERADO',
            ]);

            return;
        }

        /*
         * ⚠️ El horario queda bloqueado en el calendario de la PyME por un turno
         * que nunca existió: **es el hueco H-04**. Se registra con el id para
         * que la conciliación de T-030 pueda encontrarlo.
         */
        Log::error('No se pudo liberar el evento huérfano: el horario queda bloqueado', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'external_event_id' => $eventId,
            'codigo' => 'EVENTO_HUERFANO_PERSISTE',
        ]);
    }
}
