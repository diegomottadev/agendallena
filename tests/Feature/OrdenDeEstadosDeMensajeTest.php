<?php

namespace Tests\Feature;

use App\Jobs\ProcessMessageJob;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-039 · **Meta no garantiza el orden de los estados, y el registro no puede
 * retroceder.**
 *
 * ## Por qué importa para el negocio
 *
 * `notification_logs.status` es el único lugar donde queda escrito hasta dónde
 * llegó el recordatorio. `read` es el dato con el que T-038 **atribuye el
 * ausentismo**: separa al cliente que vio el aviso y no vino —que es un problema
 * de compromiso, y se cobra la seña— del que nunca lo vio, que es un problema de
 * entrega y lo arregla el dueño. Ese número es con el que se vende el producto.
 *
 * Meta manda los acuses por webhooks distintos y **no promete el orden**. Un
 * `delivered` que se demora en la cola y llega después del `read` hoy pisa el
 * registro y lo deja en `delivered`: el dato de que el cliente **leyó** se
 * pierde para siempre, sin error, sin log y sin forma de recuperarlo. Con eso,
 * la atribución del ausentismo se degrada sola en producción y nadie se entera.
 *
 * ## La regla que fijan estos tests
 *
 * ⚠️ **Es una decisión del team lead del 2026-08-21, pendiente de que Diego la
 * ratifique.** No está escrita en el PRD, ni en los flujos, ni en el ticket: el
 * docblock de `procesarEstadoDeMensaje()` la nombra explícitamente como *"una
 * decisión que nadie tomó"*. Si Diego la resuelve distinto, estos tests son lo
 * que hay que cambiar.
 *
 * | Regla | Qué implica |
 * | :-- | :-- |
 * | El avance es en un solo sentido: `queued` → `sent` → `delivered` → `read` | Un estado que llega tarde **nunca** hace retroceder al registro |
 * | `failed` gana siempre sobre esa progresión | Es la única información accionable para el dueño: número equivocado, cliente que bloqueó |
 * | Nada sobrescribe un `failed` | Un `sent` o un `delivered` demorado no puede "curar" un rebote |
 *
 * ## Idempotencia, que acá no sale de `processed_messages`
 *
 * ⚠️ Los `statuses` de Meta **no traen id propio** —solo el `wamid` del mensaje,
 * que se repite en los cuatro acuses—, así que la tabla `processed_messages` no
 * les sirve. La idempotencia sale de que esto es una **asignación** y no un
 * incremento: reprocesar el mismo estado escribe el mismo valor. Lo que la
 * asignación sola **no** garantiza es que no se pisen `failed_at` y
 * `failure_reason` con los de la reentrega, y eso sí se afirma acá.
 *
 * ## Canarios
 *
 * Todos los tests negativos —"no retrocedió"— llevan en el **mismo payload** un
 * segundo registro que **sí** tiene que cambiar. Sin eso, "no retrocedió" pasa
 * en vacío: si el manejador dejara de procesar estados por completo, el test
 * seguiría verde afirmando nada.
 *
 * @see EstadosDeMensajeDeMetaTest para los cuatro estados básicos y el aislamiento por tenant.
 */
class OrdenDeEstadosDeMensajeTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Bogota';

    /** El instante del webhook. El montaje usa horas anteriores a propósito. */
    private const AHORA = '2026-08-22 14:00:00';

    private const ANTES = '2026-08-22 11:30:00';

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '573001112233';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------- montaje

    private function tenant(string $slug, string $phoneNumberId): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Peluquería '.$slug,
            'slug' => $slug.'-'.uniqid(),
            'status' => 'active',
            'timezone' => self::TZ,
        ]);

        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId,
            'access_token' => 'meta',
            'settings' => ['verify_token' => 'tok', 'waba_id' => 'waba-'.$phoneNumberId],
            'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio+'.$phoneNumberId.'@peluqueria.com',
            'access_token' => 'ya29',
            'refresh_token' => '1//r',
            'status' => 'connected',
        ]);

        return $tenant;
    }

    /**
     * Una notificación ya mandada, en el estado hasta el que había avanzado.
     *
     * Cada llamada crea su propio turno: `notification_logs` tiene único
     * `(booking_id, type)` y dos registros del mismo tipo no entran en el mismo
     * turno.
     *
     * @param  array<string,mixed>  $extra  Columnas crudas del registro (`failed_at`, `failure_reason`, `sent_at`).
     */
    private function notificacion(
        Tenant $tenant,
        string $wamid,
        string $estado,
        string $tipo = NotificationLog::TIPO_RECORDATORIO_24H,
        array $extra = [],
    ): int {
        $booking = TenantContext::runAs($tenant->id, function () use ($tenant) {
            $conversacion = Conversation::create([
                'tenant_id' => $tenant->id,
                'user_phone' => self::TELEFONO,
                'current_state' => 'BOOKED',
                'state_version' => 4,
                'context_data' => ['nombre' => 'Ana', 'servicio' => 'Corte de pelo'],
                'last_interaction_at' => now(),
            ]);

            $google = Integration::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->first();

            $inicio = CarbonImmutable::parse('2026-08-23 15:00:00', 'UTC');

            return Booking::create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversacion->id,
                'integration_id' => $google->id,
                'external_event_id' => 'evt_google_'.uniqid(),
                'client_name' => 'Ana',
                'client_phone' => self::TELEFONO,
                'service_name' => 'Corte de pelo',
                'start_time' => $inicio,
                'end_time' => $inicio->addMinutes(30),
                'status' => Booking::ESTADO_AGENDADO,
                'attendance' => Booking::ASISTENCIA_PENDIENTE,
            ]);
        });

        return DB::table('notification_logs')->insertGetId(array_merge([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'type' => $tipo,
            'whatsapp_message_id' => $wamid,
            'status' => $estado,
            // Meta aceptó el envío en su momento: eso es lo que dice `sent_at`.
            'sent_at' => self::ANTES,
            'created_at' => self::ANTES,
            'updated_at' => self::ANTES,
        ], $extra));
    }

    /**
     * El webhook de estado tal como lo manda Meta: el campo es `messages` y el
     * cambio trae `statuses` en vez de `messages[]`.
     *
     * @param  array<int,array<string,mixed>>  $statuses
     * @return array<string,mixed>
     */
    private function payload(array $statuses, string $phoneNumberId = self::PHONE_NUMBER_ID): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '1360872979217448',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15551547290',
                            'phone_number_id' => $phoneNumberId,
                        ],
                        'statuses' => $statuses,
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>|null  $errores
     * @return array<string,mixed>
     */
    private function estado(string $wamid, string $estado, ?array $errores = null): array
    {
        $status = [
            'id' => $wamid,
            'status' => $estado,
            'timestamp' => '1755620000',
            'recipient_id' => self::TELEFONO,
            'conversation' => ['id' => 'conv-'.uniqid()],
        ];

        if ($errores !== null) {
            $status['errors'] = $errores;
        }

        return $status;
    }

    /**
     * La fila cruda, sin casts.
     *
     * RNF-02 · Las horas se leen como las guardó MySQL, no como las devuelve
     * Eloquent: un cast mal puesto que guarde hora local es justo lo que hay que
     * poder ver.
     *
     * @return array<string,mixed>
     */
    private function fila(int $id): array
    {
        $fila = DB::table('notification_logs')->where('id', $id)->first();

        return $fila === null ? [] : (array) $fila;
    }

    // ------------------------------------------------- no se retrocede nunca

    /**
     * **Un `delivered` demorado no puede borrar el hecho de que lo leyeron.**
     *
     * El más importante del archivo. Meta manda `delivered` y `read` por
     * webhooks separados y no promete el orden: el `delivered` puede quedarse en
     * la cola y llegar después. Si se asigna sin condición, el registro vuelve a
     * `delivered` y **la lectura desaparece**.
     *
     * Por qué importa: `read` es lo que T-038 usa para atribuir el ausentismo.
     * Un cliente que leyó el recordatorio y no vino se factura distinto de uno
     * que nunca lo vio. Perder el `read` convierte al primero en el segundo, en
     * silencio.
     *
     * Canario: en el **mismo payload** viaja un segundo recordatorio en `sent`
     * que sí tiene que avanzar a `delivered`. Sin él, "no retrocedió" también
     * sería cierto si el manejador no procesara nada.
     */
    public function test_un_delivered_que_llega_tarde_no_borra_el_read(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $leido = $this->notificacion($tenant, 'wamid.LEIDO', NotificationLog::ESTADO_LEIDO);
        $canario = $this->notificacion($tenant, 'wamid.CANARIO', NotificationLog::ESTADO_ENVIADO);

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.LEIDO', NotificationLog::ESTADO_ENTREGADO),
            $this->estado('wamid.CANARIO', NotificationLog::ESTADO_ENTREGADO),
        ])))->handle();

        $this->assertSame(NotificationLog::ESTADO_ENTREGADO, $this->fila($canario)['status'] ?? null,
            'Canario: el `delivered` no se procesó en absoluto, así que este test no probaría el orden.');

        $this->assertSame(NotificationLog::ESTADO_LEIDO, $this->fila($leido)['status'] ?? null,
            'Un `delivered` que llegó tarde hizo retroceder el registro y se perdió el `read`: '
            .'el ausentismo de T-038 ya no puede distinguir al que vio el aviso del que nunca lo vio.');
    }

    /**
     * **Un `sent` reentregado tampoco retrocede sobre un `delivered`.**
     *
     * Por qué importa: es el mismo defecto un escalón más abajo. Meta reentrega
     * el webhook cuando no le contestamos `200` a tiempo, y el reintento del
     * `sent` puede aterrizar mucho después del `delivered`. Un registro que
     * vuelve a `sent` dice *"lo intentamos"* sobre un mensaje que ya sabemos que
     * llegó, y con eso el dueño llama por teléfono a un cliente que no hacía
     * falta llamar.
     *
     * Canario en el mismo payload: un registro en `queued` que sí tiene que
     * avanzar a `sent`.
     */
    public function test_un_sent_reentregado_no_retrocede_sobre_un_delivered(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $entregado = $this->notificacion($tenant, 'wamid.ENTREGADO', NotificationLog::ESTADO_ENTREGADO);
        $canario = $this->notificacion($tenant, 'wamid.CANARIO', NotificationLog::ESTADO_ENCOLADO, extra: ['sent_at' => null]);

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.ENTREGADO', NotificationLog::ESTADO_ENVIADO),
            $this->estado('wamid.CANARIO', NotificationLog::ESTADO_ENVIADO),
        ])))->handle();

        $this->assertSame(NotificationLog::ESTADO_ENVIADO, $this->fila($canario)['status'] ?? null,
            'Canario: el `sent` no se procesó en absoluto, así que este test no probaría el orden.');

        $this->assertSame(NotificationLog::ESTADO_ENTREGADO, $this->fila($entregado)['status'] ?? null,
            'Un `sent` reentregado hizo retroceder un registro que ya estaba entregado: '
            .'el turno figura como "se intentó" cuando ya sabemos que llegó.');
    }

    /**
     * **El mismo estado dos veces deja todo como estaba.**
     *
     * Por qué importa: ⚠️ los `statuses` de Meta **no traen id propio**, así que
     * `processed_messages` no puede deduplicarlos. La única defensa es que esto
     * sea una asignación y no un incremento — y eso hay que fijarlo, porque el
     * día que alguien agregue un contador de intentos o un `updated_at` con
     * significado, la reentrega de Meta lo va a inflar sin que nadie lo pida.
     *
     * Canario: un segundo registro en el mismo payload que sí tiene que avanzar.
     */
    public function test_la_reentrega_del_mismo_estado_no_cambia_nada(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $entregado = $this->notificacion($tenant, 'wamid.ENTREGADO', NotificationLog::ESTADO_ENTREGADO);
        $canario = $this->notificacion($tenant, 'wamid.CANARIO', NotificationLog::ESTADO_ENVIADO);

        $antes = $this->fila($entregado);

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.ENTREGADO', NotificationLog::ESTADO_ENTREGADO),
            $this->estado('wamid.CANARIO', NotificationLog::ESTADO_ENTREGADO),
        ])))->handle();

        $this->assertSame(NotificationLog::ESTADO_ENTREGADO, $this->fila($canario)['status'] ?? null,
            'Canario: el `delivered` no se procesó en absoluto, así que este test no probaría la idempotencia.');

        $despues = $this->fila($entregado);

        $this->assertSame(NotificationLog::ESTADO_ENTREGADO, $despues['status'] ?? null,
            'La reentrega del mismo estado movió el registro a otro lado.');

        $this->assertSame($antes['sent_at'] ?? null, $despues['sent_at'] ?? null,
            'La reentrega del mismo estado pisó `sent_at`.');

        $this->assertNull($despues['failed_at'] ?? null,
            'La reentrega de un `delivered` marcó una hora de fallo sobre un mensaje que se entregó bien.');

        $this->assertNull($despues['failure_reason'] ?? null,
            'La reentrega de un `delivered` inventó un motivo de fallo.');
    }

    /**
     * **La reentrega de un `failed` no pisa la hora ni el motivo originales.**
     *
     * Por qué importa: `failed_at` es *cuándo rebotó*, y con eso el dueño decide
     * si todavía llega a llamar al cliente antes del turno. Si cada reentrega de
     * Meta —que puede llegar horas después— reescribe la hora con el `now()` del
     * reproceso, el registro empieza a mentir sobre cuándo pasó el problema. El
     * motivo es peor: la reentrega puede venir sin el array `errors`, y pisarlo
     * dejaría el rebote sin explicación, que es justo lo accionable.
     *
     * Canario: un segundo registro que sí tiene que avanzar en el mismo payload.
     */
    public function test_la_reentrega_de_un_failed_conserva_la_hora_y_el_motivo_originales(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $fallido = $this->notificacion($tenant, 'wamid.FALLIDO', NotificationLog::ESTADO_FALLIDO, extra: [
            'failed_at' => self::ANTES,
            'failure_reason' => '131026 · Message undeliverable: el número no tiene WhatsApp',
        ]);
        $canario = $this->notificacion($tenant, 'wamid.CANARIO', NotificationLog::ESTADO_ENVIADO);

        (new ProcessMessageJob($this->payload([
            // La reentrega llega sin `errors`, que es como Meta la manda a veces.
            $this->estado('wamid.FALLIDO', NotificationLog::ESTADO_FALLIDO),
            $this->estado('wamid.CANARIO', NotificationLog::ESTADO_ENTREGADO),
        ])))->handle();

        $this->assertSame(NotificationLog::ESTADO_ENTREGADO, $this->fila($canario)['status'] ?? null,
            'Canario: el estado no se procesó en absoluto, así que este test no probaría la idempotencia del `failed`.');

        $fila = $this->fila($fallido);

        $this->assertSame(NotificationLog::ESTADO_FALLIDO, $fila['status'] ?? null,
            'La reentrega del `failed` movió el registro a otro estado.');

        $this->assertStringStartsWith(self::ANTES, (string) ($fila['failed_at'] ?? ''),
            'La reentrega del `failed` reescribió `failed_at` con la hora del reproceso: '
            .'el registro miente sobre cuándo rebotó el mensaje.');

        $this->assertStringContainsString('131026', (string) ($fila['failure_reason'] ?? ''),
            'La reentrega del `failed` pisó el motivo original: el rebote quedó sin explicación accionable.');
    }

    // ------------------------------------------------------- `failed` gana

    /**
     * **Un `failed` que llega tarde se registra igual, aunque el mensaje se
     * hubiera leído.**
     *
     * Por qué importa: es la excepción a la regla de no retroceder, y es la que
     * paga el ticket. `failed` no es un escalón más de la progresión: es la
     * única información **accionable** para el dueño —número equivocado, cliente
     * que bloqueó el número de la PyME—. Sin esto, el turno figura avisado y no
     * lo está, y el horario se pierde.
     *
     * ⚠️ Que `failed` gane sobre `read` es decisión del team lead, sin ratificar.
     * Meta puede mandar un `read` y después un `failed` del mismo `wamid` en
     * casos raros (mensajes borrados, cuentas dadas de baja).
     */
    public function test_un_failed_que_llega_despues_de_un_read_se_registra_igual(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $leido = $this->notificacion($tenant, 'wamid.LEIDO', NotificationLog::ESTADO_LEIDO);

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.LEIDO', NotificationLog::ESTADO_FALLIDO, errores: [[
                'code' => 131_047,
                'title' => 'Re-engagement message',
                'message' => 'Message failed to send because more than 24 hours have passed',
            ]]),
        ])))->handle();

        $fila = $this->fila($leido);

        $this->assertSame(NotificationLog::ESTADO_FALLIDO, $fila['status'] ?? null,
            'Un `failed` posterior no se registró porque el registro ya estaba en `read`: '
            .'el turno figura avisado y el mensaje nunca llegó.');

        // Se afirma el texto que mandó Meta, no un formato nuestro: cómo se
        // arma el motivo es decisión del implementador, que llegue no.
        $this->assertStringContainsString('24 hours',
            (string) ($fila['failure_reason'] ?? ''),
            'El `failed` tardío no dejó el motivo que mandó Meta: sin él nadie sabe si es la ventana cerrada o un número mal cargado.');

        $this->assertStringStartsWith(self::AHORA, (string) ($fila['failed_at'] ?? ''),
            'El `failed` tardío no dejó la hora del rebote en UTC.');
    }

    /**
     * **Nada revive un `failed`: un `sent` posterior no lo cura.**
     *
     * Por qué importa: es el reverso del test anterior y el que evita el peor
     * final. Meta reentrega el `sent` del mismo `wamid` cuando el webhook
     * anterior no se le confirmó; si ese `sent` demorado aterriza después del
     * `failed`, el rebote **desaparece** y el turno vuelve a figurar avisado. El
     * dueño no llama, el cliente nunca supo del turno, y el horario se pierde
     * igual — pero ahora sin ningún rastro de por qué.
     *
     * Canario en el mismo payload: un registro en `queued` que sí avanza a
     * `sent`.
     */
    public function test_un_sent_posterior_no_revive_un_failed(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $fallido = $this->notificacion($tenant, 'wamid.FALLIDO', NotificationLog::ESTADO_FALLIDO, extra: [
            'failed_at' => self::ANTES,
            'failure_reason' => '131026 · Message undeliverable',
        ]);
        $canario = $this->notificacion($tenant, 'wamid.CANARIO', NotificationLog::ESTADO_ENCOLADO, extra: ['sent_at' => null]);

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.FALLIDO', NotificationLog::ESTADO_ENVIADO),
            $this->estado('wamid.CANARIO', NotificationLog::ESTADO_ENVIADO),
        ])))->handle();

        $this->assertSame(NotificationLog::ESTADO_ENVIADO, $this->fila($canario)['status'] ?? null,
            'Canario: el `sent` no se procesó en absoluto, así que este test no probaría que el `failed` sobrevive.');

        $fila = $this->fila($fallido);

        $this->assertSame(NotificationLog::ESTADO_FALLIDO, $fila['status'] ?? null,
            'Un `sent` que llegó tarde curó un rebote: el turno volvió a figurar avisado y nadie va a llamar al cliente.');

        $this->assertStringContainsString('131026', (string) ($fila['failure_reason'] ?? ''),
            'El `sent` tardío borró el motivo del rebote.');

        $this->assertStringStartsWith(self::ANTES, (string) ($fila['failed_at'] ?? ''),
            'El `sent` tardío borró la hora del rebote.');
    }

    // ------------------------------------------- `sent_at` en el fallo tardío

    /**
     * **Un `failed` por webhook no toca `sent_at`.**
     *
     * Está razonado en el docblock de `procesarEstadoDeMensaje()` y ningún test
     * lo fijaba. Es la **única** situación del sistema en la que una fila queda
     * `failed` con `sent_at` cargado: el rebote llegó sobre un envío que Meta
     * **sí** aceptó, así que *"cuándo lo mandamos"* sigue siendo cierto y el
     * rebote tiene columna propia (`failed_at`).
     *
     * El contraste está en el fallo **sincrónico** —`EnviarRecordatorios::marcarFallido()`
     * y el reintento rechazado del panel—, donde el mensaje nunca salió y
     * `sent_at` queda `NULL`; eso ya lo afirma `RecordatoriosFallidosTest`. Las
     * dos cosas juntas dan la definición: `sent_at` es *"salió"*, no *"llegó"*.
     *
     * Por qué importa para el negocio: es lo que permite contestar *"¿cuánto
     * tardó Meta en avisarnos que rebotó?"*. Con `sent_at` borrado, el rebote
     * queda sin punto de partida y no se puede medir si el aviso llegó a tiempo
     * de hacer algo con el turno.
     *
     * Se afirma el valor exacto, no `notNull`: un `sent_at` pisado con el `now()`
     * del reproceso también es no-nulo y estaría igual de mal.
     */
    public function test_un_failed_por_webhook_no_toca_sent_at(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $enviado = $this->notificacion($tenant, 'wamid.ENVIADO', NotificationLog::ESTADO_ENVIADO);

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.ENVIADO', NotificationLog::ESTADO_FALLIDO, errores: [[
                'code' => 131_026,
                'title' => 'Message undeliverable',
                'message' => 'Message could not be delivered',
            ]]),
        ])))->handle();

        $fila = $this->fila($enviado);

        $this->assertSame(NotificationLog::ESTADO_FALLIDO, $fila['status'] ?? null,
            'El `failed` del webhook no se registró, así que este test no probaría nada sobre `sent_at`.');

        $this->assertStringStartsWith(self::ANTES, (string) ($fila['sent_at'] ?? ''),
            'El `failed` por webhook borró o pisó `sent_at`: Meta había aceptado el envío y '
            .'"cuándo lo mandamos" sigue siendo cierto. El rebote tiene columna propia.');

        $this->assertStringStartsWith(self::AHORA, (string) ($fila['failed_at'] ?? ''),
            'El rebote no quedó fechado en `failed_at`, que es la columna donde va.');
    }

    // -------------------------------------- los tres tipos de notificación

    /**
     * **La regla de orden vale igual para la confirmación de reserva, no solo
     * para el recordatorio t-24h.**
     *
     * Por qué importa: `NotificationLog` declara tres tipos —`confirmation`,
     * `reminder_24h` y `reminder_2h`— y el camino de estados es el mismo para
     * los tres. Una confirmación que no se lee es el caso peor del producto: el
     * cliente cree que no tiene turno, no va, y el horario se pierde con la PyME
     * convencida de que avisó. Si el manejador se escribiera mirando solo el
     * recordatorio —filtrando por `type`, por ejemplo— la confirmación quedaría
     * sin cubrir y nadie lo notaría.
     *
     * Canario: un recordatorio t-2h en el mismo payload que sí tiene que
     * avanzar.
     */
    public function test_la_regla_de_orden_vale_para_la_confirmacion_de_reserva(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $confirmacion = $this->notificacion(
            $tenant,
            'wamid.CONFIRMACION',
            NotificationLog::ESTADO_LEIDO,
            tipo: NotificationLog::TIPO_CONFIRMACION,
        );

        $canario = $this->notificacion(
            $tenant,
            'wamid.CANARIO_2H',
            NotificationLog::ESTADO_ENVIADO,
            tipo: NotificationLog::TIPO_RECORDATORIO_2H,
        );

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.CONFIRMACION', NotificationLog::ESTADO_ENTREGADO),
            $this->estado('wamid.CANARIO_2H', NotificationLog::ESTADO_ENTREGADO),
        ])))->handle();

        $this->assertSame(NotificationLog::ESTADO_ENTREGADO, $this->fila($canario)['status'] ?? null,
            'Canario: el estado del recordatorio t-2h no se procesó, así que este test no probaría el tipo.');

        $this->assertSame(NotificationLog::ESTADO_LEIDO, $this->fila($confirmacion)['status'] ?? null,
            'Un `delivered` tardío hizo retroceder la confirmación de reserva que el cliente ya había leído.');
    }

    // ------------------------------------- el descarte no puede ser silencioso

    /**
     * RNF-03 · **Un estado descartado por orden queda registrado.**
     *
     * Sin esta línea, la regla de orden es invisible: el registro se queda donde
     * está, la suite queda verde, y nadie puede saber si Meta reordena una vez
     * por semana o en la mitad de los mensajes. Es la única medición de eso que
     * va a existir cuando arranque el piloto, y de ella depende decidir si la
     * regla hace falta o si estaba de más.
     *
     * ⚠️ Se escribe este test porque el descarte **no lo afirmaba nada**: quien
     * borrara el `Log::info` dejaba la suite entera en verde. Es la misma forma
     * de los defectos que aparecieron todo el día — código vivo sin cobertura.
     *
     * Se afirma el `codigo` y los dos estados, no el texto del mensaje: el texto
     * es copy y congelarlo en un test no protege nada.
     */
    public function test_el_estado_descartado_por_orden_queda_registrado(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $leido = $this->notificacion($tenant, 'wamid.LEIDO', NotificationLog::ESTADO_LEIDO);
        $canario = $this->notificacion($tenant, 'wamid.CANARIO', NotificationLog::ESTADO_ENVIADO);

        $registros = [];
        Log::listen(function ($m) use (&$registros) {
            $registros[] = ['nivel' => $m->level, 'contexto' => $m->context];
        });

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.LEIDO', NotificationLog::ESTADO_ENTREGADO),
            $this->estado('wamid.CANARIO', NotificationLog::ESTADO_ENTREGADO),
        ])))->handle();

        $this->assertSame(NotificationLog::ESTADO_ENTREGADO, $this->fila($canario)['status'] ?? null,
            'Canario: la corrida no procesó ningún estado, así que este test no probaría el registro.');

        $this->assertSame(NotificationLog::ESTADO_LEIDO, $this->fila($leido)['status'] ?? null,
            'Precondición: el `delivered` tardío no se descartó, así que no hay descarte que registrar.');

        $descartes = array_values(array_filter(
            $registros,
            static fn (array $r): bool => ($r['contexto']['codigo'] ?? null) === 'META_ESTADO_FUERA_DE_ORDEN',
        ));

        $this->assertCount(1, $descartes,
            'El estado descartado por llegar fuera de orden no dejó rastro. Sin esa línea nadie puede '
            .'contar cuán seguido Meta reordena, que es lo que dice si esta regla hace falta. Códigos '
            .'registrados: '.implode(', ', array_filter(array_map(
                static fn (array $r) => $r['contexto']['codigo'] ?? null, $registros))));

        $contexto = $descartes[0]['contexto'];

        $this->assertSame((string) $tenant->id, (string) ($contexto['tenant_id'] ?? ''),
            'El descarte no dice de qué PyME es: sin tenant no se puede contar por cliente.');

        $this->assertSame(NotificationLog::ESTADO_LEIDO, $contexto['estado_actual'] ?? null,
            'El registro no dice en qué estado estaba la fila, que es la mitad del dato.');

        $this->assertSame(NotificationLog::ESTADO_ENTREGADO, $contexto['estado_entrante'] ?? null,
            'El registro no dice qué estado llegó tarde, así que no se puede saber qué reordenó Meta.');
    }

    /**
     * Y **un estado que avanza no se registra como descarte**.
     *
     * Es la otra mitad y no es adorno: si el descarte se registrara siempre, el
     * conteo mediría tráfico en vez de reordenamientos y no serviría para
     * decidir nada. El canario del test anterior avanza en la misma corrida, así
     * que acá se afirma que **ese** avance no ensució el registro.
     */
    public function test_un_estado_que_avanza_no_se_registra_como_descarte(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);

        $enviado = $this->notificacion($tenant, 'wamid.AVANZA', NotificationLog::ESTADO_ENVIADO);

        $registros = [];
        Log::listen(function ($m) use (&$registros) {
            $registros[] = ['contexto' => $m->context];
        });

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.AVANZA', NotificationLog::ESTADO_LEIDO),
        ])))->handle();

        $this->assertSame(NotificationLog::ESTADO_LEIDO, $this->fila($enviado)['status'] ?? null,
            'Canario: el estado no avanzó, así que este test no probaría nada sobre el registro.');

        $descartes = array_filter(
            $registros,
            static fn (array $r): bool => ($r['contexto']['codigo'] ?? null) === 'META_ESTADO_FUERA_DE_ORDEN',
        );

        $this->assertSame([], array_values($descartes),
            'Un estado que avanzó se registró como descartado por orden: el conteo pasa a medir '
            .'tráfico en vez de reordenamientos y deja de servir para decidir si la regla hace falta.');
    }
}
