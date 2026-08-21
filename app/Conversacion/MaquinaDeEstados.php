<?php

namespace App\Conversacion;

use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T-018a · Núcleo de la máquina de estados conversacional.
 *
 * ## MySQL manda, Redis acelera
 *
 * Decisión cerrada en RF-A3: `conversations` es la **fuente de verdad** y Redis
 * `session:tenant:{id}:phone:{phone}` es **caché de lectura**. Ante cualquier
 * divergencia gana MySQL, y por eso acá la caché se **invalida** al escribir en
 * vez de actualizarse: una caché que se escribe en paralelo con la base tiene
 * dos maneras de quedar mal, y una que se borra tiene una sola.
 *
 * ## El lock no es por la base, es por el cliente
 *
 * Dos mensajes del mismo cliente en ráfaga —"hola" y "quiero turno" con medio
 * segundo de diferencia— pueden llegar a dos workers a la vez. Sin lock, los dos
 * leen `IDLE`, los dos aplican `MensajeInicial`, y el cliente recibe **dos
 * bienvenidas**. El lock es por `(tenant_id, user_phone)` porque esa es la
 * unidad de conversación, no la fila ni el tenant.
 *
 * ## Fuera de alcance de T-018a
 *
 * - Interpretar el mensaje de WhatsApp para decidir el evento → **T-019**.
 * - La pausa humana y la expiración por inactividad → **T-018b**.
 * - Liberar lo reservado al caer en `ERROR_FALLBACK` → **T-018c**.
 */
class MaquinaDeEstados
{
    /** Ventana del lock. Una transición son milisegundos; 10 s es holgura. */
    private const SEGUNDOS_LOCK = 10;

    /** Caché de lectura del estado. Corta, porque MySQL es barato de consultar. */
    private const SEGUNDOS_CACHE = 300;

    /**
     * Aplica un evento sobre la conversación.
     *
     * @param  array<string,mixed>  $contexto  Datos a fusionar en `context_data`.
     *
     * @throws TransicionNoDeclarada  si el evento no corresponde al estado actual.
     * @throws ConversacionOcupada    si otro proceso está transicionando la misma.
     */
    public function aplicar(Conversation $conversacion, Transicion $evento, array $contexto = [], bool $limpiarContexto = false): Estado
    {
        $lock = Cache::lock($this->claveDeLock($conversacion), self::SEGUNDOS_LOCK);

        if (! $lock->get()) {
            /*
             * No se espera al lock: si otro worker está transicionando esta misma
             * conversación, este mensaje es de una ráfaga y encolarlo detrás
             * produciría dos respuestas seguidas al cliente. Quien llama decide
             * qué hacer — normalmente, descartarlo.
             */
            throw new ConversacionOcupada($conversacion);
        }

        try {
            /*
             * Se relee dentro del lock. El objeto que nos pasaron pudo haberse
             * cargado antes de que otro worker moviera el estado, y decidir sobre
             * un estado viejo es exactamente lo que el lock viene a evitar.
             */
            $conversacion->refresh();
            $actual = Estado::from($conversacion->current_state);

            $destino = TablaDeTransiciones::destino($actual, $evento);

            if ($destino === null) {
                throw new TransicionNoDeclarada($conversacion, $actual, $evento);
            }

            $this->persistir($conversacion, $destino, $contexto, $limpiarContexto);

            Log::info('Transición de estado aplicada', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'desde' => $actual->value,
                'evento' => $evento->value,
                'hacia' => $destino->value,
                'codigo' => 'FSM_TRANSICION',
            ]);

            return $destino;
        } finally {
            $lock->release();
        }
    }

    /**
     * Estado actual, leyendo de la caché si está.
     *
     * Nunca se usa para **decidir** una transición —eso ocurre dentro del lock,
     * releyendo de MySQL— sino para responder rápido en la ingesta.
     */
    public function estadoDe(Conversation $conversacion): Estado
    {
        $valor = Cache::remember(
            $this->claveDeSesion($conversacion),
            self::SEGUNDOS_CACHE,
            fn () => $conversacion->current_state,
        );

        return Estado::from($valor);
    }

    /**
     * ¿Este evento es aplicable ahora? Sin lock y sin escribir.
     */
    public function puedeAplicar(Conversation $conversacion, Transicion $evento): bool
    {
        return TablaDeTransiciones::destino($this->estadoDe($conversacion), $evento) !== null;
    }

    /**
     * RNF-03 · Devuelve la conversación al paso anterior porque el mensaje que
     * ese paso tenía que entregar **no salió**.
     *
     * No es una transición: no pasa por la tabla y no representa nada que el
     * cliente haya hecho. Es la compensación de un avance que quedó sin sostén,
     * y por eso se escribe directo.
     *
     * ⚠️ **La versión sube igual que en un avance, nunca vuelve atrás.** Un
     * `state_version` que retrocede resucitaría botones de una vuelta anterior
     * que ya se habían dado por caducos (AC-03.2) — el bug que el contador
     * existe para impedir.
     *
     * No lanza: se la llama desde el camino de fallo, y una excepción acá taparía
     * la que explica por qué el envío no salió. Si otro worker tiene el lock, se
     * registra y se deja como está: el estado lo manda ese otro, no nosotros.
     *
     * @param  array<string,mixed>|null  $contexto  El `context_data` que había antes.
     */
    public function deshacer(Conversation $conversacion, Estado $anterior, ?array $contexto, ?string $causa = null): void
    {
        $lock = Cache::lock($this->claveDeLock($conversacion), self::SEGUNDOS_LOCK);

        if (! $lock->get()) {
            Log::warning('No se pudo deshacer el paso: otro worker tiene la conversación', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'codigo' => 'FSM_PASO_NO_DESHECHO',
            ]);

            return;
        }

        try {
            $desde = $conversacion->current_state;

            $this->persistir($conversacion, $anterior, $contexto ?? [], limpiarContexto: true);

            Log::warning('El mensaje del paso no se entregó: la conversación vuelve al paso anterior', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'desde' => $desde,
                'hacia' => $anterior->value,
                'causa' => $causa,
                'codigo' => 'FSM_PASO_DESHECHO',
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string,mixed>  $contexto
     */
    private function persistir(Conversation $conversacion, Estado $destino, array $contexto, bool $limpiarContexto = false): void
    {
        DB::transaction(function () use ($conversacion, $destino, $contexto, $limpiarContexto) {
            /*
             * El contexto se **fusiona**, no se reemplaza: el nombre capturado en
             * `GATHERING_PARAMS` tiene que seguir ahí cuando el cliente llega a
             * `SLOT_SELECTED`. Es lo que hace verificable a AC-03.1 —retomar la
             * conversación conservando los datos.
             */
            /*
             * `$limpiarContexto` lo usa el reinicio de T-021 (AC-16.3): el
             * criterio pide **limpiar el estado anterior**, no arrastrarlo. Si
             * el cliente escribe «menú» a mitad de una reserva, seguir con el
             * servicio y el horario que había elegido antes sería peor que
             * empezar de cero — es justo lo que quiso descartar.
             */
            $anterior = $limpiarContexto ? [] : ($conversacion->context_data ?? []);
            $conversacion->context_data = array_merge($anterior, $contexto);
            $conversacion->current_state = $destino->value;
            /*
             * T-019 · La version sube en **cada** transicion, incluso si el
             * estado destino es el mismo que el de origen. Es lo que hace que un
             * boton emitido en una vuelta anterior al mismo paso deje de ser
             * valido: sin esto, una lista de horarios vieja seguiria siendo
             * aceptada despues de que la conversacion reinicie (AC-03.2).
             */
            $conversacion->state_version = (int) $conversacion->state_version + 1;
            $conversacion->last_interaction_at = now();
            $conversacion->save();
        });

        // Se invalida en vez de actualizar: MySQL manda, y una caché borrada no
        // puede contradecirlo.
        Cache::forget($this->claveDeSesion($conversacion));
    }

    /**
     * Clave del catálogo de flujos §1. Lleva el tenant adentro: sin eso, dos
     * PyMEs con un cliente del mismo número colisionan (RNF-01).
     */
    private function claveDeSesion(Conversation $c): string
    {
        return "session:tenant:{$c->tenant_id}:phone:{$c->user_phone}";
    }

    private function claveDeLock(Conversation $c): string
    {
        return "fsm:tenant:{$c->tenant_id}:phone:{$c->user_phone}";
    }
}
