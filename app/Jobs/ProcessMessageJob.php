<?php

namespace App\Jobs;

use App\Conversacion\Bienvenida;
use App\Conversacion\ConversacionOcupada;
use App\Conversacion\Derivacion;
use App\Conversacion\Interactivo\Opcion;
use App\Conversacion\Interactivo\RenderizadorInteractivo;
use App\Conversacion\MotivoDeDerivacion;
use App\Conversacion\PasoEntregado;
use App\Conversacion\Interactivo\InterpreteDeRespuesta;
use App\Conversacion\Interactivo\TipoRespuesta;
use App\Conversacion\ListaDeHorarios;
use App\Conversacion\Reserva;
use App\Conversacion\ReservaTemporal;
use App\Services\Agenda\ConsultorDeDisponibilidad;
use App\Services\Agenda\DisponibilidadNoDisponible;
use App\Services\Agenda\RespuestaDeDisponibilidad;
use App\Services\Agenda\ResultadoDisponibilidad;
use App\Services\Google\GoogleIntegracionVencida;
use Carbon\CarbonImmutable;
use App\Conversacion\Estado;
use App\Conversacion\Fallback;
use App\Conversacion\MaquinaDeEstados;
use App\Conversacion\Pausa;
use App\Conversacion\Reagendamiento;
use App\Conversacion\Transicion;
use App\Conversacion\TransicionNoDeclarada;
use App\Models\Conversation;
use App\Meta\MetaAdapter;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Integration;
use App\Models\LeadSpreadsheet;
use App\Models\Message;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Recordatorios\RespuestaAlRecordatorio;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T-010 · Procesamiento asincrono del webhook de Meta.
 * T-011 · Idempotencia: una reentrega no vuelve a responderle al cliente.
 *
 * Todo lo que el request no hace ocurre aca: el endpoint responde 200 y encola,
 * y ninguna logica de negocio corre dentro del ciclo del request (RF-A2).
 *
 * Fuera de alcance a proposito:
 * - Maquina de estados y respuesta al cliente → **T-018**. El seam es
 *   `entregarAlFlujo()`.
 * - Idempotencia de las escrituras a Google → **T-030**.
 */
class ProcessMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * Reintentos declarados en el job y no solo en el comando del worker.
     *
     * `docker-compose.yml` ya pasa `--tries=3 --backoff=5`, pero eso vale para
     * **todos** los jobs de la cola y se pierde si alguien corre el worker a
     * mano. Declarado aca, la politica viaja con el job.
     */
    public int $tries = 3;

    /**
     * @param  array<string,mixed>  $payload  El cuerpo de Meta, integro.
     * @param  float|null  $recibidoEn  `microtime(true)` de cuando entro el
     *   webhook. **Se toma en el controlador, no aca:** el job puede pasar un
     *   rato en la cola, y medir desde que arranca daria un numero optimista que
     *   no es el que el cliente experimenta (AC-16.1).
     */
    public function __construct(
        public readonly array $payload,
        public readonly ?float $recibidoEn = null,
    ) {}

    /**
     * Backoff creciente: un fallo transitorio de Google o de Meta rara vez se
     * resuelve en el mismo segundo, y reintentar en rafaga sobre un tercero
     * caido solo agrega carga.
     *
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(): void
    {
        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $this->procesarCambio($change);
            }
        }
    }

    /**
     * T-033 · AC-09.4 · **La última línea: acá no se puede terminar en silencio.**
     *
     * Laravel llama a esto cuando se agotaron los tres intentos. Hasta hoy, ese
     * camino era el más mudo de todos: el job quedaba en `failed_jobs` con su
     * causa y el cliente se quedaba mirando el chat sin que nadie le dijera nada.
     * Y es el camino que atrapa **todo lo que ningún `catch` previo previó** —el
     * `RuntimeException` transitorio de `GoogleConnection` cuando el refresco de
     * token falla por un 500, una `ConnectionException` contra `freeBusy`, un
     * rate limit de Meta que no aflojó en 20 segundos—.
     *
     * Es deliberado que sea **el manejador de fallos y no un `catch` más arriba**:
     * un `catch (\Throwable)` dentro de `handle()` avisaría al cliente en el
     * primer intento y se quedaría sin los otros dos, convirtiendo un hipo de
     * cinco segundos en un "tuvimos un problema" que no hacía falta (AC-09.3).
     *
     * Nada de acá adentro puede lanzar: una excepción en el manejador de fallos
     * tapa la original, que es la que explica qué pasó.
     */
    public function failed(?\Throwable $e): void
    {
        try {
            foreach ($this->payload['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    $this->avisarDelFalloDefinitivo($change, $e);
                }
            }
        } catch (\Throwable $interno) {
            Log::error('El manejador de fallos del worker falló', [
                'codigo' => 'WORKER_FAILED_HANDLER_ROTO',
                'excepcion' => $interno::class,
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $change
     */
    private function avisarDelFalloDefinitivo(array $change, ?\Throwable $e): void
    {
        $value = $change['value'] ?? [];
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (! is_string($phoneNumberId) || $phoneNumberId === '') {
            return;
        }

        $integration = $this->resolverIntegracion($phoneNumberId);

        if ($integration === null) {
            /*
             * Sin integración no hay ni tenant ni token: no hay forma de hablarle
             * a nadie. Es el único silencio irreducible del worker, y queda
             * registrado como tal.
             */
            Log::error('Job fallido de un número sin integración: no hay a quién avisarle', [
                'phone_number_id' => $phoneNumberId,
                'codigo' => 'CLIENTE_SIN_AVISO',
            ]);

            return;
        }

        TenantContext::runAs($integration->tenant_id, function () use ($integration, $value, $e) {
            $config = BusinessSetting::withoutTenantScope()
                ->where('tenant_id', $integration->tenant_id)->first();

            // Un aviso por persona, no por mensaje: si el cliente mandó tres
            // seguidos y el job murió, tres disculpas idénticas son peores que
            // una.
            $avisados = [];

            foreach ($value['messages'] ?? [] as $message) {
                if (! is_string($message['from'] ?? null) || $message['from'] === '') {
                    continue;
                }

                $conversation = $this->resolverConversacion($message);

                if (isset($avisados[$conversation->id])) {
                    continue;
                }

                $avisados[$conversation->id] = true;

                app(Fallback::class)->porFalloDefinitivo(
                    conversacion: $conversation,
                    meta: $this->metaDe($conversation, $integration),
                    config: $config,
                    causa: $e !== null ? $e::class : null,
                );
            }
        });
    }

    /**
     * @param  array<string,mixed>  $change
     */
    private function procesarCambio(array $change): void
    {
        $value = $change['value'] ?? [];
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (! is_string($phoneNumberId) || $phoneNumberId === '') {
            Log::warning('Cambio de webhook sin phone_number_id: se descarta', [
                'field' => $change['field'] ?? null,
            ]);

            return;
        }

        $integration = $this->resolverIntegracion($phoneNumberId);

        /*
         * Un `phone_number_id` que no mapea a ningun tenant no se arregla
         * reintentando: o la suscripcion de Meta apunta a un numero que no dimos
         * de alta, o la integracion se borro. Se registra y se descarta.
         */
        if ($integration === null) {
            Log::warning('Webhook de un numero que no corresponde a ningun tenant', [
                'phone_number_id' => $phoneNumberId,
            ]);

            return;
        }

        /*
         * A partir de acá hay tenant, y toda query lo lleva. Los jobs no heredan
         * el tenant de ningún request —no hay request—, así que se declara antes
         * de la primera consulta a un modelo con aislamiento (RNF-01).
         */
        TenantContext::runAs($integration->tenant_id, function () use ($integration, $value) {
            foreach ($value['messages'] ?? [] as $message) {
                $this->procesarMensaje($integration, $message);
            }

            /*
             * T-039 · Los webhooks de estado. **`statuses` es otro array que
             * `messages`** y viene en el mismo cambio, muchas veces sin ningun
             * mensaje al lado: hasta este ticket el cambio entero se descartaba
             * porque solo se miraba `messages`.
             */
            foreach ($value['statuses'] ?? [] as $status) {
                $this->procesarEstadoDeMensaje($integration, $status);
            }
        });
    }

    /**
     * T-039 · Que paso con un mensaje que ya habiamos mandado.
     *
     * `notification_logs.status` quedaba en `sent` para siempre, y `sent` quiere
     * decir *"Meta acepto el mensaje"*, no *"al cliente le llego"*: un numero mal
     * cargado, un telefono apagado o un bloqueo dan `sent` igual y despues
     * `failed` por webhook, minutos mas tarde. Sin consumir estos estados, la
     * tasa de ausentismo de T-038 compara *"le avisamos"* contra *"no vino"*
     * usando un *"le avisamos"* que en realidad quiere decir *"lo intentamos"*.
     *
     * ## No hace falta suscribir nada nuevo en Meta
     *
     * ⚠️ **Corregido el 2026-08-21: durante un rato se creyo lo contrario**, y
     * este mismo docblock afirmaba que el manejador iba a nacer muerto hasta que
     * alguien tocara la consola de Meta. Era falso, y el error es facil de
     * repetir: **`statuses` NO es un campo de webhook.**
     *
     * En Cloud API el campo se llama **`messages`**, y ese unico campo entrega
     * los dos arrays en el mismo cambio: `value.messages[]` con lo que entra y
     * `value.statuses[]` con los acuses. Confirmado con Diego el 2026-08-21: la
     * app esta suscrita a `messages`, o sea que **estos eventos ya venian
     * llegando** y se descartaban aca, no en Meta.
     *
     * Lo que **si** es un campo aparte y **no** esta suscrito es
     * `message_template_status_update`, el que avisa cuando Meta aprueba una
     * plantilla. Por eso existe la tarea `plantillas:sincronizar`, que le
     * pregunta a Meta cada 15 minutos en vez de esperar que Meta avise. **No lo
     * confundas con este camino.**
     *
     * ## ⚠️ Que pasa con `sent_at` cuando el `failed` llega por webhook
     *
     * **No se toca, a proposito.** Un `failed` por webhook llega sobre una fila
     * que ya tenia `sent_at` cargado porque Meta **si** habia aceptado el envio, y
     * `sent_at` significa *cuando lo mandamos*, que es cierto. El rebote es un
     * hecho posterior y distinto, y por eso tiene columna propia (`failed_at`).
     *
     * Es la unica situacion del sistema en la que una fila queda `failed` con
     * `sent_at` cargado: en el fallo sincronico —`EnviarRecordatorios::marcarFallido()`
     * y el reintento rechazado del panel— el mensaje nunca salio y `sent_at` se
     * borra. **Ningun test fija esto y ninguna decision lo cubre**: si se decide
     * que `sent_at` quiere decir *"le llego"* en vez de *"salio"*, hay que
     * borrarlo aca tambien, y con su test.
     *
     * ⚠️ **La deduplicacion no aplica aca.** Los `statuses` no traen id propio,
     * asi que `processed_messages` no les sirve. Reprocesar el mismo estado
     * escribe el mismo valor: es idempotente por ser una asignacion y no un
     * incremento.
     *
     * ## El orden, que Meta no garantiza
     *
     * ⚠️ **Resuelto el 2026-08-21 por decision del team lead, pendiente de que
     * Diego la ratifique.** Los estados avanzan en un solo sentido —`queued` →
     * `sent` → `delivered` → `read`— y lo que llega tarde se descarta con un
     * `Log::info` (`META_ESTADO_FUERA_DE_ORDEN`). `failed` esta fuera de esa
     * progresion: gana sobre las cuatro y es terminal. El ranking vive en
     * `NotificationLog::AVANCE_DE_ESTADOS`.
     *
     * @param  array<string,mixed>  $status
     */
    private function procesarEstadoDeMensaje(Integration $integration, array $status): void
    {
        $wamid = $status['id'] ?? null;
        $estado = $status['status'] ?? null;

        if (! is_string($wamid) || $wamid === '' || ! is_string($estado) || $estado === '') {
            // RNF-03 · Un estado ilegible no puede desaparecer sin dejar rastro.
            Log::warning('Estado de mensaje sin id o sin estado: se descarta', [
                'tenant_id' => $integration->tenant_id,
                'codigo' => 'META_ESTADO_ILEGIBLE',
            ]);

            return;
        }

        if (! in_array($estado, NotificationLog::ESTADOS_DE_META, strict: true)) {
            // Meta puede sumar estados nuevos sin avisarnos. Se registra y se
            // descarta: escribirlo en la columna reventaria el ENUM.
            Log::warning('Estado de mensaje que no conocemos', [
                'tenant_id' => $integration->tenant_id,
                'estado' => $estado,
                'codigo' => 'META_ESTADO_DESCONOCIDO',
            ]);

            return;
        }

        /*
         * RNF-01 · El `wamid` es global de Meta y no nuestro, asi que **no
         * alcanza como llave**: un payload con un `wamid` ajeno —mal ruteado, o
         * mandado a proposito por alguien con la firma de su propia cuenta—
         * escribiria sobre el registro de otro negocio. El filtro por tenant va
         * explicito ademas del Global Scope del modelo.
         */
        $registro = NotificationLog::query()
            ->where('tenant_id', $integration->tenant_id)
            ->where('whatsapp_message_id', $wamid)
            ->first();

        if ($registro === null) {
            /*
             * Lo normal: Meta manda el estado de **todos** los mensajes salientes
             * y la mayoria son respuestas del bot, que no tienen fila en
             * `notification_logs`. No es un error, pero tampoco puede terminar en
             * silencio (RNF-03): si un dia falta el registro de un recordatorio
             * que si mandamos, esta linea es la unica que lo dice.
             */
            Log::info('Estado de Meta sobre un mensaje sin registro de notificacion', [
                'tenant_id' => $integration->tenant_id,
                'estado' => $estado,
                'codigo' => 'META_ESTADO_SIN_REGISTRO',
            ]);

            return;
        }

        /*
         * ⚠️ **Decision del team lead del 2026-08-21, pendiente de que Diego la
         * ratifique**: ningun documento la escribe.
         *
         * Un `failed` es terminal. Un `sent` o un `delivered` reentregado que
         * llega despues no puede "curar" un rebote: si lo curara, el turno
         * volveria a figurar avisado, el dueno no llamaria al cliente y el
         * horario se perderia sin rastro de por que. Se corta antes de armar
         * los cambios para que la reentrega de un `failed` **tampoco** pise
         * `failed_at` ni `failure_reason` con los del reproceso: `failed_at` es
         * *cuando rebotó*, y con eso el dueno decide si todavia llega a llamar.
         */
        if ($registro->status === NotificationLog::ESTADO_FALLIDO) {
            $this->registrarEstadoFueraDeOrden($integration->tenant_id, $registro->status, $estado);

            return;
        }

        /*
         * El avance es en un solo sentido: `queued` → `sent` → `delivered` →
         * `read`. Un estado de rango menor o igual al que ya tiene la fila
         * llego tarde y se descarta. Sin esto, un `delivered` demorado borraba
         * el `read`, que es el dato con el que T-038 separa al cliente que vio
         * el aviso y no vino del que nunca lo vio.
         *
         * `failed` esta fuera del ranking y gana: es la unica informacion
         * accionable para el dueno —numero equivocado, cliente que bloqueo— y
         * sin ella el turno figura avisado y no lo esta.
         */
        if ($estado !== NotificationLog::ESTADO_FALLIDO
            && $this->rangoDelEstado($estado) <= $this->rangoDelEstado($registro->status)) {
            $this->registrarEstadoFueraDeOrden($integration->tenant_id, $registro->status, $estado);

            return;
        }

        $cambios = ['status' => $estado];

        if ($estado === NotificationLog::ESTADO_FALLIDO) {
            /*
             * Es el caso que el envio sincronico **no puede ver**: Meta contesta
             * `200` y el rebote llega minutos despues. El motivo es lo que separa
             * *"el numero no tiene WhatsApp"* —que el dueno arregla— de *"la
             * ventana se cerro"*, que no.
             */
            $cambios['failure_reason'] = $this->motivoDelRebote($status);
            $cambios['failed_at'] = now();
        }

        $registro->forceFill($cambios)->save();
    }

    /**
     * En que escalon de la progresion cae un estado.
     *
     * El `-1` cubre un valor que no este en el ranking —hoy solo `failed`, que
     * nunca llega hasta aca— y lo deja por debajo de todo: ante la duda, el
     * estado entrante avanza en vez de perderse.
     */
    private function rangoDelEstado(?string $estado): int
    {
        return NotificationLog::AVANCE_DE_ESTADOS[$estado] ?? -1;
    }

    /**
     * RNF-03 · Un estado descartado por llegar fuera de orden no puede terminar
     * en silencio.
     *
     * `info` y no `warning` porque es **esperable**: Meta no promete el orden y
     * reordenar acuses no es un problema. Se registra igual porque *cuan
     * seguido* pasa es justo lo que el piloto tiene que poder contar, y hoy no
     * hay ninguna otra forma de saberlo.
     */
    private function registrarEstadoFueraDeOrden(string $tenantId, ?string $actual, string $entrante): void
    {
        Log::info('Estado de Meta descartado por llegar fuera de orden', [
            'tenant_id' => $tenantId,
            'estado_actual' => $actual,
            'estado_entrante' => $entrante,
            'codigo' => 'META_ESTADO_FUERA_DE_ORDEN',
        ]);
    }

    /**
     * Lo que dice Meta del rebote, en el texto que va a leer el dueno.
     *
     * @param  array<string,mixed>  $status
     */
    private function motivoDelRebote(array $status): string
    {
        $partes = [];

        foreach ($status['errors'] ?? [] as $error) {
            $partes[] = trim(implode(' · ', array_filter([
                is_string($error['title'] ?? null) ? $error['title'] : null,
                is_string($error['message'] ?? null) ? $error['message'] : null,
            ])));
        }

        $texto = trim(implode(' · ', array_filter($partes)));

        // Meta manda `failed` sin `errors` mas seguido de lo que su
        // documentacion sugiere, y un motivo vacio en el panel no explica nada.
        return $texto !== '' ? mb_substr($texto, 0, 500) : 'WhatsApp no pudo entregar el mensaje.';
    }

    /**
     * @param  array<string,mixed>  $message
     */
    private function procesarMensaje(Integration $integration, array $message): void
    {
        $messageId = $message['id'] ?? null;

        if (! is_string($messageId) || $messageId === '') {
            Log::warning('Mensaje sin id: no se puede deduplicar, se descarta', [
                'tenant_id' => $integration->tenant_id,
            ]);

            return;
        }

        /*
         * El lock cubre la ventana que la tabla sola no cubre: dos reentregas
         * simultaneas: las dos consultan `yaProcesado()` antes de que ninguna
         * haya insertado, las dos ven "no procesado", y las dos le responden al
         * cliente. El unico de la tabla las frena recien al insertar, que es
         * despues de haber respondido.
         *
         * Si no se consigue el lock, otra entrega del mismo mensaje esta siendo
         * procesada ahora mismo: no hay nada que hacer.
         */
        $lock = Cache::lock($this->claveDeLock($integration->tenant_id, $messageId), 30);

        if (! $lock->get()) {
            Log::info('Reentrega concurrente descartada: el mensaje ya se esta procesando', [
                'tenant_id' => $integration->tenant_id,
                'message_id' => $messageId,
            ]);

            return;
        }

        try {
            if ($this->yaProcesado($integration->tenant_id, $messageId)) {
                Log::info('Reentrega de Meta descartada: el mensaje ya estaba procesado', [
                    'tenant_id' => $integration->tenant_id,
                    'message_id' => $messageId,
                ]);

                return;
            }

            /*
             * T-048 · Se persiste **antes** de decidir si el bot responde, y el
             * orden es el criterio, no un detalle: AC-08.1 exige que un mensaje
             * recibido durante una pausa humana quede registrado aunque el bot
             * no conteste. Si se guardara después de `entregarAlFlujo()`, todo
             * camino que decide no responder perdería el mensaje — y son
             * justamente los casos que el operador necesita ver en el panel.
             */
            $conversation = $this->resolverConversacion($message);
            $this->persistirEntrante($conversation, $message);

            $this->entregarAlFlujo($integration, $conversation, $message);

            /*
             * Se marca **al terminar**, no al empezar. Si `entregarAlFlujo()`
             * lanza, la excepcion sube, Laravel reintenta el job y el mensaje se
             * vuelve a procesar. Marcarlo antes convertiria un fallo transitorio
             * en un mensaje perdido para siempre y en silencio.
             */
            $this->marcarProcesado($integration->tenant_id, $messageId);
        } finally {
            // Se suelta tambien si `entregarAlFlujo()` lanzo: sin esto, el
            // reintento se quedaria esperando un lock que nadie libera.
            $lock->release();
        }
    }

    /**
     * T-018a / T-019 / T-021 · Interpreta el mensaje y responde.
     *
     * El orden importa: **el reinicio se evalúa primero** (AC-16.3, "desde
     * cualquier punto"). Si se evaluara después del estado, alguien atascado en
     * un paso que no sabemos interpretar no podría salir escribiendo «menú», que
     * es justo para lo que esa palabra existe.
     *
     * @param  array<string,mixed>  $message
     */
    protected function entregarAlFlujo(Integration $integration, Conversation $conversation, array $message): void
    {
        $maquina = app(MaquinaDeEstados::class);

        /*
         * T-018b · AC-08.1 · Si el operador está atendiendo a mano, **el bot se
         * calla**. El mensaje ya quedó persistido más arriba: el criterio pide
         * que quede registrado *aunque el bot no responda*, y por eso la
         * persistencia va antes que esta comprobación.
         *
         * Se refresca el TTL: mientras el cliente siga escribiendo, la ventana
         * de 60 minutos se corre, así el bot no se despierta a mitad de una
         * conversación humana.
         */
        if (Pausa::estaPausada($conversation)) {
            Pausa::refrescar($conversation);

            Log::info('Bot en silencio: la conversación está pausada', [
                'tenant_id' => $integration->tenant_id,
                'conversation_id' => $conversation->id,
                'codigo' => 'PAUSA_SILENCIO',
            ]);

            return;
        }

        /*
         * T-037 · La respuesta a un recordatorio t-24h se resuelve **antes que
         * nada del flujo**, y por el `context.id` del webhook.
         *
         * No es un paso de la conversación: es la respuesta a un mensaje que le
         * mandamos nosotros sobre un turno que sigue existiendo. Si se evaluara
         * después del estado, el cliente que confirma el sábado un recordatorio
         * del jueves —con la conversación de vuelta en `IDLE`— recibiría la
         * bienvenida y su confirmación se perdería.
         */
        $respuestaAlRecordatorio = app(RespuestaAlRecordatorio::class);

        if ($respuestaAlRecordatorio->manejar(
            $this->metaDe($conversation, $integration),
            $conversation,
            $message,
            $this->recibidoEn,
        )) {
            /*
             * T-042 · AC-11.1 · Tocar Re-agendar deja la conversacion lista para
             * elegir horario, y la lista la arma el job: el manejador del
             * recordatorio no tiene ni el consultor de disponibilidad ni el
             * renderizador, y traerselos seria una segunda copia del flujo.
             */
            if ($respuestaAlRecordatorio->reagendando() !== null) {
                $this->ofrecerHorarios($integration, $conversation->fresh());
            }

            return;
        }

        try {
            // AC-16.3 · La salida de emergencia del cliente, desde donde sea.
            if (Bienvenida::esPalabraDeReinicio(Message::textoDe($message))) {
                $this->reiniciar($integration, $conversation, $maquina);

                return;
            }

            $estado = $maquina->estadoDe($conversation);

            // AC-16.1 · Primer contacto: bienvenida con el nombre del negocio.
            if ($estado === Estado::Idle) {
                // T-035 · El bot entendió: la racha de intentos fallidos se corta.
                Derivacion::olvidarIntentos($conversation);
                $maquina->aplicar($conversation, Transicion::MensajeInicial);
                $this->responderBienvenida($integration, $conversation->fresh());

                return;
            }

            /*
             * T-019 resuelve los botones; el texto libre en un estado avanzado
             * todavía no tiene intérprete. Se registra y **no se cambia el
             * estado** (AC-03.4).
             */
            $respuesta = app(InterpreteDeRespuesta::class)->interpretar($message, $conversation);

            /*
             * T-035 · Tocó un botón vigente: el bot entendió y la racha de
             * entradas no reconocidas se corta. Se hace acá, sobre el resultado
             * del intérprete, y no en cada rama: las ramas de abajo son las
             * acciones que hoy existen, y una acción nueva que se agregue después
             * no tendría por qué acordarse de resetear el contador.
             */
            if ($respuesta->esAccionable()) {
                Derivacion::olvidarIntentos($conversation);
            }

            if ($respuesta->tipo === TipoRespuesta::Caduca) {
                /*
                 * AC-03.2 · Tocó un botón viejo. El criterio pide que el bot
                 * **responda con el paso actual**, no que se quede callado.
                 */
                $this->responderPasoActual($integration, $conversation, $estado);

                return;
            }

            /*
             * T-026 · Tocó «Reservar»: **primero el nombre** (decisión T-007).
             * Pedirlo recién al elegir horario dejaría sin nombre a todo lead que
             * abandona antes, que es justo el que US-13 existe para capturar.
             */
            if ($respuesta->accion() === 'reservar') {
                $this->entregandoElPaso(
                    $conversation,
                    fn () => $this->pedirNombre($integration, $conversation),
                );

                return;
            }

            /*
             * T-035 · AC-20.1 · Tocó «Hablar con una persona» en el mensaje de
             * agenda llena (T-024). Hasta acá ese botón se ofrecía y no hacía
             * nada: la promesa se le hacía al cliente y nadie del negocio se
             * enteraba.
             */
            if ($respuesta->accion() === Derivacion::ACCION) {
                $this->derivar($integration, $conversation, MotivoDeDerivacion::NoAvailability);

                return;
            }

            // T-026 · Eligió un horario de la lista: se agenda.
            if (($horario = ListaDeHorarios::horarioDe($respuesta->accion())) !== null) {
                $this->agendar($integration, $conversation, $horario);

                return;
            }

            /*
             * T-026 · Está en `GATHERING_PARAMS` esperando el nombre y escribió
             * texto. **Esto no es "interpretar texto libre"** —lo que T-019
             * prohíbe— sino tomarlo como dato: no se adivina ninguna intención,
             * se guarda lo que escribió.
             */
            if ($estado === Estado::GatheringParams && $this->esperaNombre($conversation)) {
                $this->entregandoElPaso(
                    $conversation,
                    fn () => $this->recibirNombre($integration, $conversation, Message::textoDe($message)),
                );

                return;
            }

            // T-023 · Pidió ver más horarios.
            if (($pagina = ListaDeHorarios::paginaSolicitada($respuesta->accion())) !== null) {
                /*
                 * AC-05.4 · **No se recalcula nada**: el criterio pide que pedir
                 * la página siguiente no vuelva a preguntar parámetros. Se
                 * reconsulta la disponibilidad porque puede haber cambiado entre
                 * páginas —alguien pudo reservar mientras tanto— pero el contexto
                 * de la conversación no se toca.
                 */
                $this->entregandoElPaso(
                    $conversation,
                    fn () => $this->ofrecerHorarios($integration, $conversation, $pagina),
                );

                return;
            }

            Log::info('Mensaje en un estado sin interprete todavia', [
                'tenant_id' => $integration->tenant_id,
                'conversation_id' => $conversation->id,
                'estado' => $estado->value,
                'accion' => $respuesta->accion(),
                'codigo' => 'FSM_SIN_INTERPRETE',
            ]);

            $this->intentoNoReconocido($integration, $conversation);
        } catch (ConversacionOcupada) {
            // Rafaga: otro worker esta transicionando esta misma conversacion.
            // Se descarta; T-011 ya garantizo que el mensaje quedo persistido.
            Log::info('Mensaje descartado: la conversacion esta siendo transicionada', [
                'tenant_id' => $integration->tenant_id,
                'conversation_id' => $conversation->id,
                'codigo' => 'FSM_CONVERSACION_OCUPADA',
            ]);
        } catch (TransicionNoDeclarada $e) {
            Log::info('Transicion no declarada: el estado no cambia', [
                'tenant_id' => $integration->tenant_id,
                'conversation_id' => $conversation->id,
                'estado' => $e->estadoActual->value,
                'codigo' => 'FSM_TRANSICION_INVALIDA',
            ]);

            $this->intentoNoReconocido($integration, $conversation);
        }
    }

    /**
     * RNF-03 · Corre un paso del flujo atando su avance a que el mensaje salga.
     *
     * Ver `PasoEntregado`: si el envío se cae, el estado vuelve atrás para que
     * el reintento de la cola vuelva a recorrer **el mismo camino**. Sin esto,
     * un rate limit de Meta convierte el reintento en un «no te entiendo» que
     * termina sin excepción, y con eso se apaga el fallback de AC-09.4.
     *
     * @param  \Closure():?string  $paso
     */
    private function entregandoElPaso(Conversation $conversation, \Closure $paso): void
    {
        app(PasoEntregado::class)->ejecutar($conversation, $paso);
    }

    /**
     * T-035 · El bot no entendió. A la enésima vez, llama a una persona.
     *
     * El criterio dice *"se deriva **en vez de** repetir el recordatorio del
     * paso"*: por debajo del umbral no cambia nada —hoy el recordatorio de
     * `responderPasoActual()` está sin escribir y solo se registra—, y al
     * llegar al umbral se deriva y el bot se calla.
     */
    private function intentoNoReconocido(Integration $integration, Conversation $conversation): void
    {
        $intentos = Derivacion::contarIntentoFallido($conversation);

        if ($intentos < Derivacion::INTENTOS_PARA_DERIVAR) {
            return;
        }

        Log::info('Se agotaron los intentos: la conversación se deriva a una persona', [
            'tenant_id' => $integration->tenant_id,
            'conversation_id' => $conversation->id,
            'intentos' => $intentos,
            'codigo' => 'DERIVACION_POR_INTENTOS',
        ]);

        $this->derivar($integration, $conversation, MotivoDeDerivacion::UnknownInput);
    }

    /**
     * Deriva y le avisa al cliente **una sola vez**.
     *
     * AC-20.4 · Si ya había un pendiente, `activar()` devuelve `false` y el
     * mensaje no sale: al cliente que ya escuchó *"te va a escribir una
     * persona"* repetírselo solo le confirma que del otro lado hay un robot.
     */
    private function derivar(Integration $integration, Conversation $conversation, MotivoDeDerivacion $motivo): void
    {
        if (! Derivacion::activar($conversation, $motivo)) {
            return;
        }

        $this->adapterDe($integration, $conversation)
            ->enviarTexto(
                $conversation,
                Derivacion::mensajePara(
                    BusinessSetting::withoutTenantScope()
                        ->where('tenant_id', $conversation->tenant_id)->first()
                ),
                $this->recibidoEn,
            );
    }

    /**
     * T-026 · Pide el nombre antes de ofrecer horarios (decisión T-007).
     *
     * @return string|null  El `wamid` entregado, o `null` si el envío no salió.
     */
    private function pedirNombre(Integration $integration, Conversation $conversation): ?string
    {
        // Se marca que estamos esperándolo: sin esto, el próximo texto que
        // escriba el cliente no se distinguiría de cualquier otro.
        app(MaquinaDeEstados::class)->aplicar(
            $conversation, Transicion::PideNombre, ['esperando' => 'nombre']
        );

        return $this->adapterDe($integration, $conversation)->enviarTexto(
            $conversation,
            '¡Genial! ¿A nombre de quién reservo el turno?',
            $this->recibidoEn,
        );
    }

    private function esperaNombre(Conversation $conversation): bool
    {
        return ($conversation->context_data['esperando'] ?? null) === 'nombre';
    }

    /**
     * T-026 · Guarda el nombre y pasa a ofrecer horarios.
     *
     * @return string|null  El `wamid` entregado, o `null` si el envío no salió.
     */
    private function recibirNombre(Integration $integration, Conversation $conversation, ?string $texto): ?string
    {
        $nombre = trim((string) $texto);

        if ($nombre === '' || mb_strlen($nombre) > 80) {
            return $this->adapterDe($integration, $conversation)->enviarTexto(
                $conversation, '¿Me decís tu nombre así reservo el turno?', $this->recibidoEn,
            );
        }

        // T-035 · Un nombre válido es texto que el bot **sí** entendió: la racha
        // de intentos fallidos se corta acá igual que con un botón.
        Derivacion::olvidarIntentos($conversation);

        app(MaquinaDeEstados::class)->aplicar(
            $conversation, Transicion::NombreRecibido, ['nombre' => $nombre, 'esperando' => null]
        );

        /*
         * T-045 · AC-13.1 · El lead se vuelca **al salir de `GATHERING_PARAMS`**,
         * no al reservar. Es la razón entera por la que la captura del nombre se
         * movió acá (decisión T-007): el lead que se va sin reservar es justo el
         * que US-13 existe para capturar. Escribiéndolo al confirmar el turno, la
         * planilla tendría solo a los que ya son clientes.
         */
        $this->volcarLead($conversation->fresh() ?? $conversation);

        return $this->ofrecerHorarios($integration, $conversation->fresh());
    }

    /**
     * T-045 · Encola la escritura del lead en la planilla de la PyME.
     *
     * **Encola y no escribe.** Sheets adentro del camino del chat le suma su
     * latencia a cada mensaje —RNF-04 ya está incumplido con las dos llamadas a
     * terceros que hay— y, peor, ataría el turno a que una API de terceros
     * conteste: AC-22.4 es explícito en que un fallo de Sheets no puede impedir
     * que el turno quede creado y confirmado.
     *
     * Sin planilla vinculada no se encola nada: T-044 es una pantalla que se
     * puede no haber tocado nunca, y un job por lead para una PyME que no la usó
     * es cola que nadie va a consumir.
     */
    private function volcarLead(Conversation $conversation, ?Booking $booking = null): void
    {
        $hayPlanilla = LeadSpreadsheet::query()
            // Filtro explícito además del Global Scope (RNF-01).
            ->where('tenant_id', $conversation->tenant_id)
            ->exists();

        if (! $hayPlanilla) {
            return;
        }

        VolcarLeadJob::dispatch(
            (string) $conversation->tenant_id,
            (int) $conversation->id,
            $booking?->id,
        );
    }

    /**
     * T-026 · Crea el turno y se lo confirma al cliente.
     * T-029 · Y antes de eso, comprueba que el horario siga siendo suyo.
     *
     * El orden lo fija el ticket: evento en Google, fila en `bookings`, y
     * **recién ahí** la confirmación.
     *
     * ## Las dos guardas de T-029 van antes de `SlotElegido`
     *
     * Transicionar primero dejaría al cliente que pierde el horario en
     * `SLOT_SELECTED`, desde donde no hay forma declarada de volver a
     * `SELECTING_SLOT` — y AC-18.1 pide justamente relistarle **sin volver a
     * pedirle los datos**. Apartando y verificando antes, el que pierde nunca se
     * movió del paso en el que estaba y la lista nueva le llega ahí mismo.
     *
     * El doble toque sigue cubierto por el sello de T-019: la versión sube al
     * aplicar `SlotElegido`, que ocurre igual antes de hablar con Google.
     */
    private function agendar(Integration $integration, Conversation $conversation, CarbonImmutable $horario): void
    {
        $tenant = $conversation->tenant;
        $adapter = $this->adapterDe($integration, $conversation);

        $config = BusinessSetting::withoutTenantScope()->where('tenant_id', $tenant->id)->first();
        $google = Integration::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
            ->first();

        if ($config === null || $google === null) {
            $adapter->enviarTexto($conversation, $this->textoDeCortesia($config), $this->recibidoEn);

            return;
        }

        // T-029 · AC-18.2 · El horario se aparta al elegirlo. La exclusión la da
        // Redis: si la llave ya estaba, otro cliente llegó primero.
        if (! ReservaTemporal::apartar($conversation, $horario)) {
            $this->avisarQueElHorarioSePerdio($integration, $conversation, $horario, 'apartado_por_otro');

            return;
        }

        // T-029 · AC-18.1 · Y se le vuelve a preguntar a Google, por si se ocupó
        // mientras el cliente leía la lista.
        $libre = $this->horarioSigueLibre($tenant, $config, $google, $horario);

        if ($libre === false) {
            ReservaTemporal::liberar($conversation, $horario);
            $this->avisarQueElHorarioSePerdio($integration, $conversation, $horario, 'ocupado_en_google');

            return;
        }

        if ($libre === null) {
            /*
             * RNF-03 · No se pudo verificar. **No verificar no es lo mismo que
             * verificar que está libre**: escribir en Google a ciegas es cómo se
             * agenda encima de un turno existente. El cliente recibe el mensaje
             * de cortesía y el chat no queda colgado.
             */
            ReservaTemporal::liberar($conversation, $horario);
            $adapter->enviarTexto($conversation, $config->fallback_message, $this->recibidoEn);

            return;
        }

        app(MaquinaDeEstados::class)->aplicar(
            $conversation, Transicion::SlotElegido, ['horario' => $horario->toIso8601String()]
        );
        $conversation->refresh();

        $reserva = app(Reserva::class);

        try {
            $booking = $reserva->agendar($conversation, $tenant, $config, $google, $horario);
        } catch (GoogleIntegracionVencida) {
            $booking = null;
        }

        if ($booking === null) {
            /*
             * T-029 · El turno no se creó, así que el horario vuelve al pozo
             * común: dejarlo apartado el TTL entero se lo escondería a los demás
             * clientes por un turno que no existe.
             */
            ReservaTemporal::liberar($conversation, $horario);

            /*
             * T-018c · No se pudo agendar. `ERROR_FALLBACK` libera lo reservado
             * —incluido el evento que pueda haber quedado huérfano en Google— y
             * vuelve a `IDLE`, en vez de dejar al cliente atascado en
             * `SLOT_SELECTED` con un horario que nunca se confirmó.
             */
            app(Fallback::class)->manejar(
                conversacion: $conversation,
                meta: $this->metaDe($conversation, $integration),
                config: $config,
                recibidoEn: $this->recibidoEn,
                eventoHuerfano: $reserva->eventoHuerfano(),
                google: $google,
            );

            return;
        }

        /*
         * T-042 · El turno nuevo ya existe y esta persistido: **recien ahora** se
         * suelta el viejo. El orden es el criterio del ticket, no un detalle:
         * borrar primero deja sin ningun turno al cliente si Google falla al
         * crear el nuevo.
         */
        $reagendamiento = app(Reagendamiento::class);
        $viejo = $reagendamiento->turnoQueSeMueve($conversation);

        if ($viejo !== null && $viejo->id !== $booking->id) {
            $reagendamiento->soltarElViejo($viejo, $booking, $conversation);
            $conversation->refresh();
        }

        /*
         * El turno ya está en Google y en `bookings`. **De acá en adelante nada
         * puede impedir que el cliente se entere**: un turno que existe y que el
         * cliente no conoce es un horario bloqueado para nadie, y la PyME lo
         * descubre cuando el turno no se presenta.
         */
        $this->marcarComoAgendada($conversation);

        // La fila ya está persistida: recién ahora se confirma (criterio del ticket).
        $this->confirmarAlCliente($adapter, $conversation, $booking, $tenant);

        /*
         * T-045 · AC-13.2 · Y **después** de que el cliente tenga su confirmación
         * se actualiza su fila en la planilla, con la fecha del turno. El orden
         * es AC-22.4: la planilla es conveniencia, el turno es lo que se cobra.
         */
        $this->volcarLead($conversation->fresh() ?? $conversation, $booking);
    }

    /**
     * T-029 · ¿El horario que el cliente eligió sigue libre en Google?
     *
     * Se pregunta de nuevo **inmediatamente antes de crear el evento**: la lista
     * se armó con una foto de hace unos segundos, y en ese rato la PyME pudo
     * bloquear el horario desde su propio calendario (UC-2.8).
     *
     * @return bool|null  `null` si **no se pudo verificar**. Es un tercer valor
     *   y no un `false` porque las dos situaciones se le contestan distinto al
     *   cliente: una es "ese horario ya no está" y la otra es "se nos rompió
     *   algo". Comunicar la segunda como la primera lo mandaría a elegir otro
     *   horario que tampoco va a poder reservar.
     */
    private function horarioSigueLibre(
        Tenant $tenant,
        BusinessSetting $config,
        Integration $google,
        CarbonImmutable $horario,
    ): ?bool {
        try {
            $resultado = app(ConsultorDeDisponibilidad::class)->consultar(
                $google, $tenant, $config, CarbonImmutable::now($tenant->timezone),
            );
        } catch (DisponibilidadNoDisponible | GoogleIntegracionVencida) {
            /*
             * RNF-03 · La re-consulta es una llamada más a un tercero en el
             * camino crítico: que se caiga no puede colgar el chat. Quien llama
             * manda el mensaje de cortesía.
             */
            Log::warning('No se pudo re-verificar el horario antes de crear el evento', [
                'tenant_id' => $tenant->id,
                'integracion' => 'google_calendar',
                'codigo' => 'RECHEQUEO_NO_DISPONIBLE',
            ]);

            return null;
        }

        foreach ($resultado->horarios as $libre) {
            if ($libre->getTimestamp() === $horario->getTimestamp()) {
                return true;
            }
        }

        return false;
    }

    /**
     * T-029 · AC-18.1 · Le avisa que ese horario ya no está y le muestra la
     * lista actualizada.
     *
     * ⚠️ **Ningún documento fija este texto.** Lo que sí está fijado es que las
     * tres situaciones son distintas y no se pueden comunicar igual: *"se nos
     * rompió algo"* (el fallback de RNF-03), *"no tengo lugar"* (agenda llena de
     * T-024) y esta, *"ese horario en particular ya no está, elegí otro"*. Un
     * cliente que recibe la equivocada saca la conclusión equivocada — y con las
     * dos primeras se va a buscar turno a otro lado teniendo horarios libres.
     *
     * El relistado ocurre **en el mismo paso**: la conversación nunca salió de
     * `SELECTING_SLOT`, así que el nombre que ya dio sigue en `context_data` y no
     * se le vuelve a pedir nada.
     */
    private function avisarQueElHorarioSePerdio(
        Integration $integration,
        Conversation $conversation,
        CarbonImmutable $horario,
        string $motivo,
    ): void {
        Log::info('El horario elegido ya no estaba disponible al confirmar', [
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'horario' => $horario->utc()->toIso8601String(),
            'motivo' => $motivo,
            'codigo' => 'SLOT_PERDIDO',
        ]);

        $this->adapterDe($integration, $conversation)->enviarTexto(
            $conversation,
            'Ese horario ya no está disponible. Estos sí:',
            $this->recibidoEn,
        );

        $this->ofrecerHorarios($integration, $conversation->fresh());
    }

    /**
     * Mueve la conversación a `BOOKED` **sin poder frenar la confirmación**.
     *
     * `ConversacionOcupada` acá subía hasta el `catch` de `entregarAlFlujo()` y
     * el mensaje se descartaba entero — con el turno ya creado. Y la ventana en
     * que ocurre es justo esta: entre `SLOT_SELECTED` y `BOOKED`, mientras
     * Google crea el evento, otro worker puede tomar la conversación. Lo que se
     * perdía no era una transición: era la única confirmación que el cliente iba
     * a recibir.
     *
     * Que el estado no se haya podido mover es higiene nuestra, y se registra.
     * El aviso al cliente sigue igual.
     */
    private function marcarComoAgendada(Conversation $conversation): void
    {
        try {
            app(MaquinaDeEstados::class)->aplicar($conversation->fresh(), Transicion::ReservaConfirmada);
        } catch (ConversacionOcupada | TransicionNoDeclarada $e) {
            Log::warning('El turno se agendó pero la conversación no pasó a BOOKED', [
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'codigo' => 'RESERVA_ESTADO_NO_APLICADO',
                'excepcion' => $e::class,
            ]);
        }
    }

    /**
     * Confirma el turno y **deja constancia de si salió**.
     *
     * `enviarTexto()` devuelve `null` cuando Meta rechaza el envío sin lanzar
     * —un `131047` daría lo mismo tres veces, así que no se reintenta— y ese
     * retorno no lo miraba nadie: la confirmación se evaporaba y el turno quedaba
     * igual de agendado. La fila en `notification_logs` es lo que hace
     * contestable la pregunta *"¿qué turnos quedaron sin avisar?"*, que un
     * `grep` sobre el log no responde.
     */
    private function confirmarAlCliente(MetaAdapter $adapter, Conversation $conversation, Booking $booking, Tenant $tenant): void
    {
        $texto = app(Reserva::class)->textoDeConfirmacion($booking, $tenant);

        try {
            $wamid = $adapter->enviarTexto($conversation, $texto, $this->recibidoEn);
        } catch (\Throwable $e) {
            /*
             * Un rate limit sube para que la cola reintente —y el reintento
             * actualiza este mismo registro si sale—, pero el turno ya existe: si
             * el intento no dejara constancia, un fallo que agota los reintentos
             * dejaría el turno sin avisar y sin rastro de que quedó así.
             */
            $this->registrarConfirmacion($booking, null);

            throw $e;
        }

        $this->registrarConfirmacion($booking, $wamid);
    }

    /**
     * El registro es **por turno**: el único `(booking_id, type)` de T-009 hace
     * que un reintento que sí sale actualice la fila en vez de duplicarla.
     */
    private function registrarConfirmacion(Booking $booking, ?string $wamid): void
    {
        $entregada = $wamid !== null;

        NotificationLog::updateOrCreate(
            ['booking_id' => $booking->id, 'type' => NotificationLog::TIPO_CONFIRMACION],
            [
                // Explícito y no solo por el contexto: la lista de turnos sin
                // avisar de una PyME no puede traer los de otra (RNF-01).
                'tenant_id' => $booking->tenant_id,
                'whatsapp_message_id' => $wamid,
                'status' => $entregada ? NotificationLog::ESTADO_ENVIADO : NotificationLog::ESTADO_FALLIDO,
                'sent_at' => $entregada ? now() : null,
            ]
        );
    }

    private function textoDeCortesia(?BusinessSetting $config): string
    {
        return $config?->fallback_message
            ?? BusinessSetting::valoresPorDefecto()['fallback_message'];
    }

    private function adapterDe(Integration $integration, Conversation $conversation): MetaAdapter
    {
        return new MetaAdapter($this->metaDe($conversation, $integration));
    }

    private function metaDe(Conversation $conversation, Integration $porDefecto): Integration
    {
        return Integration::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->first() ?? $porDefecto;
    }

    /**
     * T-023 · Consulta la disponibilidad y manda la lista.
     *
     * Une T-022 (qué está libre), T-023 (cómo se muestra) y T-024 (qué se dice
     * cuando no hay nada). Los tres vacíos de T-024 llegan hasta acá con su
     * causa, así que un bug de configuración no se comunica como "no hay lugar".
     */
    private function ofrecerHorarios(Integration $integration, Conversation $conversation, int $pagina = 0): ?string
    {
        $tenant = $conversation->tenant;

        $google = Integration::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
            ->first();

        $config = BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)->first();

        $meta = Integration::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->first() ?? $integration;

        $adapter = new MetaAdapter($meta);

        if ($google === null || $config === null) {
            /*
             * Sin calendario conectado no se puede saber qué está libre, y eso
             * **es un problema nuestro**, no una agenda llena (AC-19.4). Va el
             * texto de cortesía, no un "no hay turnos" que sería mentira.
             */
            Log::error('Se pidieron horarios sin calendario conectado o sin configuración', [
                'tenant_id' => $tenant->id,
                'codigo' => 'AGENDA_NO_CONSULTABLE',
            ]);

            return $adapter->enviarTexto(
                $conversation,
                $config?->fallback_message ?? BusinessSetting::valoresPorDefecto()['fallback_message'],
                $this->recibidoEn,
            );
        }

        try {
            $resultado = app(ConsultorDeDisponibilidad::class)->consultar(
                $google, $tenant, $config, CarbonImmutable::now($tenant->timezone),
            );
        } catch (DisponibilidadNoDisponible | GoogleIntegracionVencida) {
            // RNF-03 · Google no responde: mensaje de cortesía, chat no colgado.
            return $adapter->enviarTexto($conversation, $config->fallback_message, $this->recibidoEn);
        }

        /*
         * T-029 · AC-18.3 · Lo que otro cliente tiene apartado no se ofrece. En
         * la ventana en que el primero está creando su evento, `freeBusy` todavía
         * dice que el horario está libre —porque lo está—, así que sin este
         * filtro los dos lo verían y los dos lo elegirían.
         *
         * Se reconstruye el resultado en vez de filtrar más abajo para que un
         * vacío por apartados se comunique con la misma causa que cualquier otro
         * vacío: para el cliente es una agenda llena, no un problema nuestro.
         */
        if ($resultado->hayHorarios()) {
            $libres = ReservaTemporal::sinLosApartadosPorOtros(
                $resultado->horarios, $tenant->id, $conversation->user_phone,
            );

            $resultado = $libres === []
                ? ResultadoDisponibilidad::agendaLlena($resultado->diasConsultados)
                : ResultadoDisponibilidad::conHorarios($libres, $resultado->diasConsultados);
        }

        if (! $resultado->hayHorarios()) {
            // T-024 arma el texto según **por qué** no hay horarios.
            $r = RespuestaDeDisponibilidad::para($resultado, $tenant, $config);

            if ($r['opciones'] === []) {
                // Sin salida que ofrecer —es un problema nuestro, AC-19.4—: texto
                // plano y nada que tocar.
                return $adapter->enviarTexto($conversation, $r['texto'], $this->recibidoEn);
            }

            /*
             * T-035 · La salida deja de ser decorativa: se manda como botón.
             * Hasta acá T-024 devolvía la opción «Hablar con una persona» y el
             * job la descartaba mandando solo el texto, así que el cliente con la
             * agenda llena no tenía **nada** que tocar.
             *
             * ⚠️ Todas las opciones se mapean a la misma acción porque hoy T-024
             * ofrece una sola, y es la persona: el recorte Pareto difirió «ver
             * más adelante» (AC-19.2). Cuando vuelva, la acción tiene que viajar
             * junto al título desde `RespuestaDeDisponibilidad`.
             */
            return $adapter->enviarInteractivo(
                $conversation,
                app(RenderizadorInteractivo::class)->botones(
                    $conversation,
                    $r['texto'],
                    array_map(fn (string $t) => new Opcion(Derivacion::ACCION, $t), $r['opciones']),
                ),
                $this->recibidoEn,
            );
        }

        /*
         * Se transiciona **antes** de renderizar: el `id` de cada fila sella el
         * estado emisor, así que si se armara la lista todavía en
         * `GATHERING_PARAMS`, los botones nacerían caducos y ningún horario
         * podría elegirse (AC-03.2).
         */
        if (Estado::from($conversation->current_state) !== Estado::SelectingSlot) {
            app(MaquinaDeEstados::class)->aplicar($conversation, Transicion::ParametrosCompletos);
            $conversation->refresh();
        }

        $mensaje = app(ListaDeHorarios::class)->mensaje(
            $conversation, $tenant, $resultado->horarios, $pagina,
        );

        if ($mensaje === null) {
            return $adapter->enviarTexto($conversation, 'No tengo más horarios para mostrarte.', $this->recibidoEn);
        }

        return $adapter->enviarInteractivo($conversation, $mensaje, $this->recibidoEn);
    }

    /** AC-16.3 · Vuelve al menú inicial limpiando lo que hubiera a medias. */
    private function reiniciar(Integration $integration, Conversation $conversation, MaquinaDeEstados $maquina): void
    {
        /*
         * T-035 · La salida de emergencia también limpia la racha de intentos
         * fallidos. Arrancar de cero con dos strikes encima haría que el cliente
         * que se destrabó solo termine derivado por su primer tropiezo nuevo.
         */
        Derivacion::olvidarIntentos($conversation);

        if ($maquina->estadoDe($conversation) !== Estado::Idle) {
            // `limpiarContexto: true` — el criterio pide limpiar el estado
            // anterior, no arrastrar el servicio y el horario que se descartaron.
            $maquina->aplicar($conversation, Transicion::Reinicia, limpiarContexto: true);
        }

        $maquina->aplicar($conversation->fresh(), Transicion::MensajeInicial);
        $this->responderBienvenida($integration, $conversation->fresh());
    }

    private function responderBienvenida(Integration $integration, Conversation $conversation): void
    {
        $tenant = $conversation->tenant;
        $config = BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)->first();

        $metaIntegration = Integration::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->first() ?? $integration;

        if ($config === null) {
            // T-014 crea la configuración con cada tenant; si falta, es un bug
            // nuestro y no se le habla al cliente con datos inventados.
            Log::error('Tenant sin configuración: no se puede armar la bienvenida', [
                'tenant_id' => $tenant->id,
                'codigo' => 'CONFIG_AUSENTE',
            ]);

            /*
             * T-033 · AC-09.4 · Antes esto era un `return` seco y el primer
             * contacto del cliente con el producto era el silencio. No se le
             * inventa una bienvenida con datos que no tenemos, pero **algo tiene
             * que recibir**: va el texto de cortesía por defecto, que es el único
             * que se puede usar cuando la configuración del tenant es lo que
             * falta.
             */
            (new MetaAdapter($metaIntegration))->enviarTexto(
                $conversation,
                BusinessSetting::valoresPorDefecto()['fallback_message'],
                $this->recibidoEn,
            );

            return;
        }

        $mensaje = app(Bienvenida::class)->mensaje($conversation, $tenant, $config);

        (new MetaAdapter($metaIntegration))
            ->enviarInteractivo($conversation, $mensaje, $this->recibidoEn);
    }

    /**
     * AC-03.2 · Le recuerda al cliente en qué paso está.
     *
     * ⚠️ Hoy solo cubre `IDLE`; el resto de los pasos son T-023 y siguientes. Se
     * registra en vez de improvisar un texto por estado: un mensaje inventado
     * acá sería copy que después hay que desandar.
     */
    private function responderPasoActual(Integration $integration, Conversation $conversation, Estado $estado): void
    {
        Log::info('Botón caduco: hay que responder con el paso actual', [
            'tenant_id' => $integration->tenant_id,
            'conversation_id' => $conversation->id,
            'estado' => $estado->value,
            'codigo' => 'FSM_RECORDAR_PASO',
        ]);
    }

    /**
     * Garantiza que exista la fila de la conversación. **No decide estados.**
     *
     * Resolver o crear la conversación por `(tenant_id, user_phone)` es de la
     * ingesta —el índice compuesto de T-009 existe para este camino— y hace
     * falta acá porque un mensaje necesita a qué colgarse. Las transiciones son
     * T-018: acá la conversación nace en `IDLE` y nadie la mueve.
     *
     * @param  array<string,mixed>  $message
     */
    private function resolverConversacion(array $message): Conversation
    {
        $conversation = Conversation::firstOrCreate(
            ['user_phone' => (string) ($message['from'] ?? '')],
            [
                'current_state' => Conversation::ESTADO_INICIAL,
                'last_interaction_at' => now(),
            ]
        );

        if (! $conversation->wasRecentlyCreated) {
            $conversation->forceFill(['last_interaction_at' => now()])->save();
        }

        return $conversation;
    }

    /**
     * @param  array<string,mixed>  $message
     */
    private function persistirEntrante(Conversation $conversation, array $message): void
    {
        /*
         * `firstOrCreate` sobre el `wamid`: si el job se reintenta después de
         * haber persistido pero antes de terminar, el reintento no duplica la
         * fila. El único de la tabla es la garantía real; esto evita la
         * excepción en el camino normal.
         */
        Message::firstOrCreate(
            ['whatsapp_message_id' => (string) $message['id']],
            [
                'conversation_id' => $conversation->id,
                'direction' => Message::ENTRANTE,
                'type' => (string) ($message['type'] ?? 'unknown'),
                'content' => Message::textoDe($message),
                // El payload íntegro queda para los tipos que no son texto y
                // para poder reprocesar sin pedirle nada a Meta.
                'payload' => $message,
            ]
        );
    }

    private function claveDeLock(string $tenantId, string $messageId): string
    {
        // Toda llave de Redis lleva el tenant adentro: sin eso, colisionan entre
        // PyMEs (RNF-01).
        return "dedup:tenant:{$tenantId}:msg:{$messageId}";
    }

    private function yaProcesado(string $tenantId, string $messageId): bool
    {
        return DB::table('processed_messages')
            ->where('tenant_id', $tenantId)
            ->where('message_id', $messageId)
            ->exists();
    }

    private function marcarProcesado(string $tenantId, string $messageId): void
    {
        /*
         * `insertOrIgnore` y no `insert`: el unico de la tabla es la garantia
         * real, y si otro worker gano la carrera pese al lock —lock vencido por
         * un procesamiento largo, por ejemplo— el segundo insert no puede tumbar
         * un mensaje que ya se entrego bien.
         */
        DB::table('processed_messages')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'message_id' => $messageId,
            'processed_at' => now(),
        ]);
    }

    private function resolverIntegracion(string $phoneNumberId): ?Integration
    {
        return Integration::query()
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->where('account_identifier', $phoneNumberId)
            ->first();
    }
}
