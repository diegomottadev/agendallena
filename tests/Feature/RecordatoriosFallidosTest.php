<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * T-039 · Recordatorios fallidos: detección y reintento (US-24).
 *
 * `notification_logs.status` admite `'failed'` desde el primer día: **el modelo
 * de datos previó que un recordatorio puede no salir y después nadie escribió
 * qué pasa entonces.**
 *
 * Importa más de lo que su tamaño sugiere porque contamina la medición: si un
 * porcentaje de los recordatorios nunca salió, la tasa de ausentismo de T-038 no
 * dice nada — no sabemos si el recordatorio no funciona o si no se envió.
 *
 * Los estados de mensaje que manda Meta por webhook (`sent`, `delivered`, `read`,
 * `failed`) están en `EstadosDeMensajeDeMetaTest`: es ingesta, no panel.
 *
 * ## Contrato que estos tests fijan
 *
 * Ningún criterio nombra URLs ni columnas. Las elijo acá y quedan escritas:
 *
 * | Dónde | Qué |
 * | :-- | :-- |
 * | `notification_logs.failure_reason` | Texto del motivo. **La columna no existe: la agrega este ticket** |
 * | `notification_logs.failed_at` | Instante UTC del fallo. `sent_at` sigue siendo *cuándo salió* y queda `NULL` |
 * | `GET /panel/recordatorios-fallidos` | Variable de vista `fallidos` |
 * | `POST /panel/recordatorios-fallidos/{log}/reintentar` | Reintento manual |
 *
 * Cada entrada de `fallidos` lleva `notification_log_id`, `booking_id`,
 * `client_name`, `cuando`, `tiempo_restante_minutos`, `tiempo_restante` y `motivo`.
 *
 * ⚠️ **`tiempo_restante_minutos` es un agregado mío.** El criterio pide "tiempo
 * restante" y eso es un texto para leer; afirmarlo por su redacción congelaría en
 * un test una decisión de copy. El entero es lo que se puede verificar sin fijar
 * palabras, y el texto se afirma solo como "no está vacío".
 *
 * ## Sobre el candado anti-duplicados, que acá se toca de cerca
 *
 * La decisión § 2 de `plan-for-diego/decisiones-tomadas.md` fijó que **un
 * recordatorio frenado no consume el candado** —el único `(booking_id, type)`—
 * y por eso se reintenta solo en la corrida siguiente.
 *
 * **Un recordatorio que falló al enviarse es el caso opuesto y no lo contradice:**
 * el candado ya está tomado, porque `EnviarRecordatorios::tomarElCandado()` inserta
 * la fila **antes** de llamar a Meta. Por eso este ticket necesita un reintento
 * **manual**: la corrida automática no lo va a volver a intentar nunca.
 *
 * El corolario está afirmado en `test_el_reintento_manual_no_crea_un_registro_nuevo`:
 * el reintento **actualiza la fila existente**. Insertar una segunda chocaría con
 * el único, que es la nota de riesgo del propio ticket.
 *
 * ## Ambigüedades del criterio que tuve que resolver
 *
 * ⚠️ **"Agotados los reintentos" no existe hoy para esta ruta.** `recordatorios:enviar`
 * corre desde el scheduler, **no desde la cola**: no tiene `$tries` ni backoff, así
 * que el único intento *es* el último. Estos tests afirman el estado final tras ese
 * intento. Si "reintentos" significa varios intentos automáticos antes de dar por
 * fallido, es alcance que ningún ticket describe.
 *
 * ⚠️ **"Agrupados" no dice agrupados por qué.** Lo leo como *juntos en una
 * pantalla*, no como agrupados por una clave. No hay test que fije un agrupamiento.
 *
 * ⚠️ **"El turno no se marca como recordado" no tiene columna que mirar.** No
 * existe `bookings.reminded`. Lo que sí existe es la lectura que hace T-038: un
 * turno con fila en `notification_logs` cuenta como *"le llegó el recordatorio y
 * no contestó"*. Ese es el "marcado como recordado" observable, y es el que se
 * afirma.
 *
 * ⚠️ **La ventana de dos horas se mide contra el inicio del turno**, no contra el
 * fin ni contra un t-2h teórico. Es la única lectura de AC-24.4 que no inventa un
 * dato.
 *
 * ⚠️ **Qué rol puede reintentar no está definido.** Los tests usan `owner`, que
 * puede todo. No afirmo nada sobre `staff`.
 */
class RecordatoriosFallidosTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = '/panel/recordatorios-fallidos';

    private const COMANDO = 'recordatorios:enviar';

    /**
     * **UTC−5 todo el año, sin horario de verano.**
     *
     * No es `America/Argentina/Buenos_Aires` a propósito: la suite corre en UTC,
     * BA está a −3, y BA y Santiago comparten offset parte del año — ya hicieron
     * pasar por casualidad un test de husos en T-031. Bogotá no coincide con UTC
     * ningún día del año, así que un "tiempo restante" o un "cuándo" calculado en
     * UTC salta siempre, y salta por exactamente 5 horas.
     */
    private const TZ = 'America/Bogota';

    /** El "ahora" de todos los tests, en UTC. En Bogotá son las 09:00. */
    private const AHORA = '2026-08-22 14:00:00';

    /** 25 h por delante: bien afuera de la ventana de dos horas de AC-24.3. */
    private const TURNO_LEJANO = '2026-08-23 15:00:00';

    /** 1 h 30 por delante: adentro de la ventana, o sea **no** se reintenta (AC-24.4). */
    private const TURNO_CERCANO = '2026-08-22 15:30:00';

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

    private function tenant(string $slug = 'piloto', string $phoneNumberId = self::PHONE_NUMBER_ID): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Peluquería '.$slug,
            'slug' => $slug.'-'.uniqid(),
            'status' => 'active',
            'timezone' => self::TZ,
        ]);

        /*
         * T-050 · La cuenta de WhatsApp es **de esta PyME**. Las plantillas se
         * dejan registradas como aprobadas directamente en `settings` en vez de
         * correr el alta: el alta le pega a Meta y acá lo que se prueba es el
         * envío, no el trámite.
         */
        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId,
            'access_token' => 'meta',
            'settings' => [
                'verify_token' => 'tok',
                'waba_id' => 'waba-'.$phoneNumberId,
                'plantillas' => [
                    'recordatorio_turno_24h' => 'APPROVED',
                    'confirmacion_reserva' => 'APPROVED',
                    'aviso_turno_2h' => 'APPROVED',
                ],
            ],
            'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio+'.$phoneNumberId.'@peluqueria.com',
            'access_token' => 'ya29',
            'refresh_token' => '1//r',
            'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        return $tenant;
    }

    private function usuario(Tenant $tenant, Role $rol = Role::Owner): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $rol->etiqueta(),
            'email' => $rol->value.'-'.uniqid().'@panel.test',
            'password' => 'secreto123',
            'role' => $rol,
        ]);
    }

    /** Un turno agendado que arranca en `$inicioUtc`, con su conversación. */
    private function turno(Tenant $tenant, string $inicioUtc, string $cliente = 'Ana'): Booking
    {
        $inicio = CarbonImmutable::parse($inicioUtc, 'UTC');

        return TenantContext::runAs($tenant->id, function () use ($tenant, $inicio, $cliente) {
            $conversacion = Conversation::create([
                'tenant_id' => $tenant->id,
                'user_phone' => self::TELEFONO,
                'current_state' => 'BOOKED',
                'state_version' => 4,
                'context_data' => ['nombre' => $cliente, 'servicio' => 'Corte de pelo'],
                'last_interaction_at' => now(),
            ]);

            $google = Integration::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->first();

            return Booking::create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversacion->id,
                'integration_id' => $google->id,
                'external_event_id' => 'evt_google_'.uniqid(),
                'client_name' => $cliente,
                'client_phone' => self::TELEFONO,
                'service_name' => 'Corte de pelo',
                'start_time' => $inicio,
                'end_time' => $inicio->addMinutes(30),
                'status' => Booking::ESTADO_AGENDADO,
                'attendance' => Booking::ASISTENCIA_PENDIENTE,
            ]);
        });
    }

    /**
     * El registro de un recordatorio que no salió: lo que este ticket detecta.
     *
     * ⚠️ Escribe `failure_reason` y `failed_at`, que **hoy no existen**. Es el
     * rojo legítimo del ticket, no un montaje roto: su nota de riesgo dice
     * textual *"`notification_logs` no tiene columna para el motivo del fallo,
     * solo para el estado. Agregarla en este ticket."*
     */
    private function registroFallido(Tenant $tenant, Booking $booking, string $motivo = 'Meta rechazó el envío'): int
    {
        return DB::table('notification_logs')->insertGetId([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'type' => NotificationLog::TIPO_RECORDATORIO_24H,
            'whatsapp_message_id' => null,
            'status' => NotificationLog::ESTADO_FALLIDO,
            'sent_at' => null,
            'failure_reason' => $motivo,
            'failed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------- dobles

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: un segundo `fake()` para simular el
     * rechazo no reemplazaría al primero, el doble del error nunca se usaría y el
     * test pasaría verde sin haber probado nada. Ya pasó tres veces en T-026.
     *
     * @param  bool  $metaAcepta  `false` simula el rechazo definitivo de Meta.
     */
    private function fakes(bool $metaAcepta = true): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'id' => 'evt_google_123',
                'status' => 'confirmed',
                'extendedProperties' => ['private' => ['origen' => 'agendallena']],
            ]),

            'graph.facebook.com/*' => function (Request $request) use ($metaAcepta) {
                if (str_contains($request->url(), 'message_templates')) {
                    return Http::response(['id' => '123', 'status' => 'APPROVED', 'category' => 'UTILITY']);
                }

                if (! $metaAcepta) {
                    /*
                     * 500 y no 429 ni `130429`: esos son rate limit, que
                     * `MetaAdapter` lanza como excepción para que la cola
                     * reintente. Este es el rechazo que **no** se arregla
                     * esperando, que es el que deja el registro en `failed`.
                     */
                    return Http::response([
                        'error' => ['message' => 'Message failed to send', 'code' => 131_026],
                    ], 500);
                }

                return Http::response(['messages' => [['id' => 'wamid.REINTENTO_'.uniqid()]]]);
            },
        ]);
    }

    /** Cuántos mensajes se le mandaron a Meta hasta ahora. */
    private function mensajesEnviados(): int
    {
        $n = 0;

        foreach (Http::recorded() as [$req, $_res]) {
            if (str_contains($req->url(), 'graph.facebook.com')
                && str_contains($req->url(), '/messages')) {
                $n++;
            }
        }

        return $n;
    }

    // ---------------------------------------------------------- utilidades

    /**
     * La fila cruda como array: nunca por el modelo, y con `?? null` para que una
     * columna que todavía no existe falle como aserción y no como warning de PHP.
     *
     * @return array<string,mixed>
     */
    private function registroDe(Booking $booking): array
    {
        $fila = DB::table('notification_logs')
            ->where('booking_id', $booking->id)
            ->where('type', NotificationLog::TIPO_RECORDATORIO_24H)
            ->first();

        return $fila === null ? [] : (array) $fila;
    }

    /** @return array<int,array<string,mixed>> */
    private function fallidosDeLaVista(TestResponse $respuesta): array
    {
        $respuesta->assertViewHas('fallidos');

        $lista = $respuesta->viewData('fallidos');
        $lista = is_object($lista) && method_exists($lista, 'all') ? $lista->all() : (array) $lista;

        return array_values(array_map(fn ($f) => (array) $f, $lista));
    }

    private function reintentar(User $usuario, int $registroId): TestResponse
    {
        return $this->actingAs($usuario)->post(self::PANEL."/{$registroId}/reintentar");
    }

    // ------------------------------------------------------------- AC-24.1

    /**
     * **AC-24.1 · El registro queda fallido, con motivo y con hora.**
     *
     * Por qué importa: hoy `marcarFallido()` escribe `status = 'failed'` y borra
     * `sent_at`, y con eso queda una fila que dice *"algo pasó"* y nada más. El
     * dueño ve que el cliente no recibió el recordatorio y no puede saber si fue
     * un rechazo de Meta, un número mal cargado o la cuenta suspendida — que son
     * tres acciones distintas de su lado.
     *
     * El montaje tiene el turno a t-24h exactas, así que la tarea lo agarra.
     */
    public function test_agotados_los_reintentos_el_registro_queda_fallido_con_motivo_y_hora(): void
    {
        $this->fakes(metaAcepta: false);

        $tenant = $this->tenant();
        // t-24h exactas desde el "ahora" fijado: cae en el centro de la ventana.
        $booking = $this->turno($tenant, '2026-08-23 14:00:00');

        $this->artisan(self::COMANDO)->assertExitCode(0);

        $registro = $this->registroDe($booking);

        $this->assertNotSame([], $registro,
            'La tarea no dejó ningún registro del recordatorio que no salió.');

        $this->assertSame(NotificationLog::ESTADO_FALLIDO, $registro['status'] ?? null,
            'El recordatorio no salió y el registro no quedó en `failed`.');

        $this->assertNotEmpty(trim((string) ($registro['failure_reason'] ?? '')),
            'El registro no dice **por qué** falló: sin motivo, el dueño no sabe si es algo que puede arreglar.');

        $this->assertNotNull($registro['failed_at'] ?? null,
            'El registro no dice **cuándo** falló: sin hora no se puede saber si el fallo es de recién o de ayer.');

        // ⚠️ `array_key_exists` y no `??`: el coalescente dispara **también** cuando
        // la clave existe y vale `null`, así que el fallback pisaba el caso que este
        // assert viene a afirmar y la línea no podía pasar con ninguna implementación.
        $this->assertArrayHasKey('sent_at', $registro,
            'El registro no trae la columna `sent_at`.');
        $this->assertNull($registro['sent_at'],
            '`sent_at` quedó cargado en un recordatorio que nunca salió: es la columna de "cuándo se envió".');
    }

    /**
     * **AC-24.1 · Un recordatorio fallido no cuenta como turno recordado.**
     *
     * Por qué importa: es el motivo por el que este ticket existe. La tasa de
     * ausentismo de T-038 separa al cliente que **recibió** el recordatorio y no
     * contestó del que **nunca supo** que tenía turno. Hoy `turnosConRecordatorio()`
     * cuenta cualquier fila de `notification_logs`, incluida la fallida, así que
     * un cliente que nunca recibió nada aparece como "le avisamos y nos ignoró".
     *
     * Con eso, el número que sostiene el precio del producto mide otra cosa.
     */
    public function test_un_recordatorio_fallido_no_marca_el_turno_como_recordado(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        // Un turno de ayer, ya marcado como ausente: es el que entra al KPI.
        $booking = $this->turno($tenant, '2026-08-21 15:00:00', 'Ana');
        DB::table('bookings')->where('id', $booking->id)
            ->update(['attendance' => Booking::ASISTENCIA_AUSENTE]);

        DB::table('notification_logs')->insert([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'type' => NotificationLog::TIPO_RECORDATORIO_24H,
            'status' => NotificationLog::ESTADO_FALLIDO,
            'sent_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $respuesta = $this->actingAs($usuario)
            ->get('/panel/asistencia?desde=2026-08-21&hasta=2026-08-21')
            ->assertSuccessful();

        $marcados = collect($respuesta->viewData('marcados'))
            ->map(fn ($m) => (array) $m)
            ->firstWhere('id', $booking->id);

        $this->assertNotNull($marcados, 'El turno marcado no aparece en el listado del rango.');

        $this->assertSame('sin_recordatorio', $marcados['respuesta_recordatorio'] ?? null,
            'Un recordatorio que **falló** cuenta como recordatorio entregado: el cliente que nunca supo '
            .'de su turno queda mezclado con el que nos ignoró, y la tasa de ausentismo deja de medir lo que dice medir.');
    }

    // ------------------------------------------------------------- AC-24.2

    /**
     * **AC-24.2 · Los fallidos de turnos futuros, con turno, cliente y tiempo restante.**
     *
     * Por qué importa: un recordatorio fallido de un turno que ya pasó es
     * historia; uno de un turno de mañana todavía se puede arreglar llamando al
     * cliente. La lista existe para lo segundo, y mezclarlas la vuelve inútil por
     * volumen a las pocas semanas.
     */
    public function test_los_recordatorios_fallidos_de_turnos_futuros_aparecen_en_el_panel(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $futuro = $this->turno($tenant, self::TURNO_LEJANO, 'Ana Futura');
        $this->registroFallido($tenant, $futuro, 'Meta rechazó el envío');

        $pasado = $this->turno($tenant, '2026-08-20 15:00:00', 'Pedro Pasado');
        $this->registroFallido($tenant, $pasado);

        $fallidos = $this->fallidosDeLaVista(
            $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
        );

        $this->assertCount(1, $fallidos,
            'La lista no trae exactamente el recordatorio fallido del turno futuro: '
            .'o falta, o se coló el del turno que ya pasó.');

        $entrada = $fallidos[0];

        $this->assertSame($futuro->id, $entrada['booking_id'] ?? null,
            'La entrada no identifica el turno.');
        $this->assertSame('Ana Futura', $entrada['client_name'] ?? null,
            'La entrada no dice a qué cliente no le llegó el recordatorio: sin nombre no se lo puede llamar.');
        $this->assertNotEmpty(trim((string) ($entrada['motivo'] ?? '')),
            'La entrada no dice por qué falló.');
    }

    /**
     * **AC-24.2 · "Tiempo restante" y "cuándo" van en la hora del negocio (RNF-02).**
     *
     * Por qué importa: el dueño decide si llega a llamar al cliente mirando este
     * número. En UTC, un turno de las 10 de la mañana en Bogotá se muestra a las
     * 15 y la decisión que toma es la equivocada.
     *
     * ⚠️ **El instante no es ambiguo de huso a propósito.** Bogotá es UTC−5 los
     * 365 días del año, sin horario de verano: no coincide con UTC ningún día, ni
     * con Buenos Aires. Si el implementador formateara en UTC, `cuando` daría
     * `23/08/2026 15:00` en vez de `23/08/2026 10:00` — cinco horas de diferencia
     * que ninguna casualidad estacional puede tapar. Con Buenos Aires o Santiago
     * este test podría pasar por casualidad, como ya ocurrió en T-031.
     */
    public function test_el_tiempo_restante_y_la_hora_van_en_la_zona_del_negocio(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $booking = $this->turno($tenant, self::TURNO_LEJANO, 'Ana Futura');
        $this->registroFallido($tenant, $booking);

        $entrada = $this->fallidosDeLaVista(
            $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
        )[0];

        $this->assertSame('23/08/2026 10:00', $entrada['cuando'] ?? null,
            'El turno se muestra en UTC y no en la hora de la PyME: son las 10:00 en Bogotá, no las 15:00.');

        // 2026-08-22 14:00 UTC → 2026-08-23 15:00 UTC son 25 h = 1500 minutos.
        $this->assertSame(1500, $entrada['tiempo_restante_minutos'] ?? null,
            'El tiempo restante hasta el turno está mal calculado: el dueño decide con este número si llega a llamar.');

        $this->assertNotEmpty(trim((string) ($entrada['tiempo_restante'] ?? '')),
            'No hay un tiempo restante legible: el criterio pide mostrarlo, no solo tenerlo.');
    }

    /**
     * **RNF-01 · Los fallidos de una PyME no se ven desde el panel de otra.**
     *
     * Por qué importa: la lista trae nombre y teléfono de clientes. Una fuga acá
     * es una fuga de datos personales de la clientela de otro negocio.
     *
     * Va con canario: primero se verifica que la PyME dueña **sí** ve el suyo,
     * porque si no, "la otra no lo ve" sería cierto en una pantalla vacía.
     */
    public function test_el_panel_de_una_pyme_no_lista_los_recordatorios_fallidos_de_otra(): void
    {
        $mia = $this->tenant('mia', '1111111111');
        $ajena = $this->tenant('ajena', '2222222222');

        $propio = $this->turno($mia, self::TURNO_LEJANO, 'Cliente Propio');
        $this->registroFallido($mia, $propio);

        $delOtro = $this->turno($ajena, self::TURNO_LEJANO, 'Cliente Ajeno');
        $this->registroFallido($ajena, $delOtro);

        $fallidos = $this->fallidosDeLaVista(
            $this->actingAs($this->usuario($mia))->get(self::PANEL)->assertSuccessful(),
        );

        $nombres = array_map(fn (array $f) => (string) ($f['client_name'] ?? ''), $fallidos);

        $this->assertContains('Cliente Propio', $nombres,
            'Canario: la PyME no ve ni su propio recordatorio fallido.');
        $this->assertNotContains('Cliente Ajeno', $nombres,
            'Se filtró al panel el cliente de otra PyME.');
    }

    /**
     * **RNF-01 · Nadie reintenta el recordatorio de otra PyME.**
     *
     * Por qué importa: reintentar es mandar un WhatsApp. Un reintento cruzado le
     * manda a un cliente un mensaje desde el número equivocado, con los datos de
     * un turno de otro negocio.
     *
     * ⚠️ La comparación tiene que ser **como string**: `tenant_id` es UUID y un
     * `(int)` sobre un UUID devuelve `1` para todos, con lo que el chequeo pasaría
     * siempre y la fuga seguiría abierta.
     */
    public function test_no_se_puede_reintentar_el_recordatorio_de_otra_pyme(): void
    {
        $this->fakes();

        $mia = $this->tenant('mia', '1111111111');
        $ajena = $this->tenant('ajena', '2222222222');

        $delOtro = $this->turno($ajena, self::TURNO_LEJANO, 'Cliente Ajeno');
        $registroAjeno = $this->registroFallido($ajena, $delOtro);

        $this->reintentar($this->usuario($mia), $registroAjeno)->assertForbidden();

        $this->assertSame(0, $this->mensajesEnviados(),
            'Se le mandó un WhatsApp a la clienta de otra PyME desde el panel equivocado.');

        $this->assertSame(
            NotificationLog::ESTADO_FALLIDO,
            DB::table('notification_logs')->where('id', $registroAjeno)->value('status'),
            'El registro de la otra PyME se tocó igual.',
        );
    }

    // ------------------------------------------------------------- AC-24.3

    /**
     * **AC-24.3 · Con más de dos horas, el reintento manual reenvía.**
     *
     * Por qué importa: es la única forma que tiene el dueño de recuperar el
     * recordatorio. La corrida automática **no** lo va a volver a intentar: el
     * candado `(booking_id, type)` ya está tomado desde el primer intento.
     */
    public function test_con_mas_de_dos_horas_el_reintento_manual_reenvia_y_refleja_el_resultado(): void
    {
        $this->fakes();

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $booking = $this->turno($tenant, self::TURNO_LEJANO, 'Ana Futura');
        $registroId = $this->registroFallido($tenant, $booking);

        $this->reintentar($usuario, $registroId)
            ->assertRedirect()
            ->assertSessionHas('exito');

        $this->assertSame(1, $this->mensajesEnviados(),
            'El reintento manual no le mandó nada a Meta: el cliente sigue sin su recordatorio.');

        $registro = (array) DB::table('notification_logs')->where('id', $registroId)->first();

        $this->assertSame(NotificationLog::ESTADO_ENVIADO, $registro['status'] ?? null,
            'El reintento salió bien y el registro sigue diciendo `failed`: el panel va a pedir reintentarlo para siempre.');
        $this->assertNotNull($registro['sent_at'] ?? null,
            'El reintento no dejó registrada la hora de envío.');
        $this->assertNotEmpty($registro['whatsapp_message_id'] ?? null,
            'El reintento no guardó el `wamid`: sin él, la respuesta del cliente al botón no se puede atar a este turno.');
    }

    /**
     * **AC-24.3 · Un reintento que también falla se refleja como tal.**
     *
     * Por qué importa: *"refleja el resultado"* incluye el resultado malo. Un
     * reintento que falla en silencio y muestra "listo" es peor que no tener
     * botón: el dueño se queda tranquilo y el cliente igual no viene.
     */
    public function test_si_el_reintento_manual_tambien_falla_el_panel_lo_dice(): void
    {
        $this->fakes(metaAcepta: false);

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $booking = $this->turno($tenant, self::TURNO_LEJANO, 'Ana Futura');
        $registroId = $this->registroFallido($tenant, $booking, 'Primer intento rechazado');

        $this->reintentar($usuario, $registroId)
            ->assertRedirect()
            ->assertSessionHas('error');

        $registro = (array) DB::table('notification_logs')->where('id', $registroId)->first();

        $this->assertSame(NotificationLog::ESTADO_FALLIDO, $registro['status'] ?? null,
            'Meta rechazó el reintento y el registro quedó como enviado: el turno figura avisado y no lo está.');
        $this->assertArrayHasKey('sent_at', $registro,
            'El registro no trae la columna `sent_at`.');
        $this->assertNull($registro['sent_at'],
            'Un reintento rechazado dejó cargada la hora de envío.');
        $this->assertNotNull($registro['failed_at'] ?? null,
            'El reintento fallido no actualizó la hora del fallo: el panel muestra la del intento viejo.');
    }

    /**
     * **El reintento actualiza la fila, no crea una segunda.**
     *
     * Por qué importa: es la nota de riesgo del propio ticket. El único
     * `(booking_id, type)` de T-009 es el candado anti-duplicados de T-037, y un
     * reintento que intente insertar una fila nueva choca contra él — o peor, lo
     * "arregla" alguien agregando un número de intento al único, y con eso se
     * abre la puerta a que el cliente reciba dos recordatorios.
     */
    public function test_el_reintento_manual_no_crea_un_registro_nuevo(): void
    {
        $this->fakes();

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $booking = $this->turno($tenant, self::TURNO_LEJANO, 'Ana Futura');
        $registroId = $this->registroFallido($tenant, $booking);

        $this->reintentar($usuario, $registroId)->assertRedirect();

        $this->assertSame(
            1,
            DB::table('notification_logs')->where('booking_id', $booking->id)->count(),
            'El reintento dejó más de un registro para el mismo turno: el candado anti-duplicados dejó de ser único.',
        );
    }

    // ------------------------------------------------------------- AC-24.4

    /**
     * **AC-24.4 · Con el turno a menos de dos horas, el recordatorio de 24 h no se reintenta.**
     *
     * Por qué importa: un mensaje que dice *"te recordamos tu turno de mañana"*
     * mandado noventa minutos antes del turno confunde más de lo que ayuda — el
     * cliente cree que el turno es otro día. Es el mismo criterio por el que
     * T-037 no manda recordatorios de turnos creados con menos de 24 h.
     *
     * ⚠️ **Con canario en el mismo test.** "No se reintentó" es cierto en vacío:
     * si la ruta no existiera, o el fake nunca se usara, la aserción pasaría sin
     * probar nada. Por eso primero se reintenta el turno lejano —que **sí** tiene
     * que salir y deja el contador en 1— y recién después el cercano, afirmando
     * que el contador **no se movió**.
     *
     * ⚠️ **El instante no es ambiguo de huso.** El turno cercano son las 15:30 UTC
     * = 10:30 en Bogotá (UTC−5 todo el año), con el "ahora" a las 14:00 UTC:
     * 1 h 30 de distancia. Aunque alguien comparara en hora local contra un `now()`
     * en UTC, la distancia seguiría siendo la misma — la resta de dos instantes no
     * depende de la zona. Lo que este montaje descarta es lo contrario: que el
     * cercano parezca lejano por un error de conversión de 5 horas.
     */
    public function test_un_recordatorio_de_24h_no_se_reintenta_con_el_turno_a_menos_de_dos_horas(): void
    {
        $this->fakes();

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $lejano = $this->turno($tenant, self::TURNO_LEJANO, 'Ana Lejana');
        $registroLejano = $this->registroFallido($tenant, $lejano);

        $cercano = $this->turno($tenant, self::TURNO_CERCANO, 'Beto Cercano');
        $registroCercano = $this->registroFallido($tenant, $cercano);

        // Canario: este sí tiene que salir.
        $this->reintentar($usuario, $registroLejano)->assertRedirect();
        $this->assertSame(1, $this->mensajesEnviados(),
            'Canario: el reintento del turno lejano tampoco mandó nada, así que este test no probaría nada.');

        $this->reintentar($usuario, $registroCercano)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, $this->mensajesEnviados(),
            'Se le mandó un «recordatorio de mañana» a un cliente que tiene el turno en hora y media.');

        $this->assertSame(
            NotificationLog::ESTADO_FALLIDO,
            DB::table('notification_logs')->where('id', $registroCercano)->value('status'),
            'El reintento frenado igual marcó el registro como enviado.',
        );
    }
}
