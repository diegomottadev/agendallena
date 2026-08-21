<?php

namespace Tests\Feature;

use App\Conversacion\Interactivo\IdSellado;
use App\Meta\AltaDeCuenta;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-037 · El recordatorio t-24h sale, y sale una sola vez.
 *
 * **Es el único número del pitch que se traduce directo a plata.** El lean
 * canvas promete bajar el ausentismo de 20-35 % a menos del 8 %; si el
 * recordatorio no llega, o llega dos veces, o llega con la hora en UTC, esa
 * cifra no tiene con qué sostenerse.
 *
 * Acá se prueba **el lado del envío**. Lo que pasa cuando el cliente toca un
 * botón está en `RespuestaAlRecordatorioT24hTest`.
 *
 * ## Decisiones que tomé para poder escribir el test
 *
 * ⚠️ **El nombre del comando lo elijo yo.** Ni el ticket ni US-10 lo fijan: los
 * dos dicen "tarea del scheduler". Sigo la convención de `conversaciones:expirar`
 * (T-018b), único precedente del proyecto: `recurso:verbo`.
 *
 * ⚠️ **Los bordes se prueban a 9 y a 11 minutos, no a 10 exactos.** El criterio
 * dice "±10 minutos" y no dice si el minuto 10 entra o sale. Probar el borde
 * exacto sería fijar en un test una decisión que nadie tomó.
 *
 * ## ⚠️ Por qué cambió el montaje el 2026-08-20 (T-050)
 *
 * Este archivo montaba su integración de Meta con `settings => ['verify_token']`
 * y nada más. **T-050 hizo del `waba_id` una precondición del envío:** con el
 * modelo de una cuenta de WhatsApp por PyME, un tenant sin `waba_id` y sin sus
 * plantillas aprobadas en su propia cuenta **no es un tenant a medio configurar
 * que igual puede mandar — es uno que no puede mandar nada**, porque las
 * plantillas se aprueban por cuenta y la suya no tiene ninguna.
 *
 * Es una **expectativa vieja, no una regresión**: el comportamiento cambió a
 * propósito. Por eso el montaje ahora da de alta la cuenta del tenant antes de
 * correr la tarea, y **ninguna aserción de este archivo cambió**: las mismas 21
 * afirmaciones sobre el recordatorio siguen en pie, hechas sobre un tenant que
 * bajo el modelo nuevo puede existir.
 *
 * El detalle del alta y del freno está en `AltaDeCuentaDeWhatsAppTest` y en
 * `RecordatorioConPlantillaNoAprobadaTest`.
 */
class RecordatorioT24hTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ Elección mía: ni el ticket ni la historia nombran el comando.
     * Ver la nota de clase.
     */
    private const COMANDO = 'recordatorios:enviar';

    /** Nombre exacto de la plantilla aprobada en Meta (T-002). */
    private const PLANTILLA = 'recordatorio_turno_24h';

    private const IDIOMA = 'es_AR';

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '5493764278402';

    /**
     * **UTC−5 todo el año.**
     *
     * No es `America/Argentina/Buenos_Aires` a propósito: la suite corre en UTC
     * y BA está a −3, pero Santiago y BA comparten offset parte del año y ya
     * hicieron pasar por casualidad un test de husos en T-031. Bogotá no
     * coincide con UTC ni con BA **ningún día del año**, así que un recordatorio
     * que se mande en UTC salta siempre.
     */
    private const TZ = 'America/Bogota';

    /** El "ahora" de todos los tests, en UTC. */
    private const AHORA = '2026-08-20 12:00:00';

    private Tenant $tenant;

    private int $salientes = 0;

    private int $recordatorios = 0;

    /**
     * Las integraciones de Meta a las que todavía no se les corrió el alta.
     *
     * @var array<int,Integration>
     */
    private array $altasPendientes = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC'));
        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        $this->tenant = $this->crearTenant('Peluquería Sur', self::PHONE_NUMBER_ID, self::TZ);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------- montaje

    private function crearTenant(string $nombre, string $phoneNumberId, string $tz): Tenant
    {
        $tenant = Tenant::create([
            'name' => $nombre, 'slug' => 'piloto-'.uniqid(),
            'status' => 'active', 'timezone' => $tz,
        ]);

        // T-050 · La cuenta de WhatsApp es **de esta PyME**: una WABA por tenant,
        // distinta de la de cualquier otro. Sin ella no hay plantilla que mandar.
        $meta = Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId, 'access_token' => 'meta',
            'settings' => ['verify_token' => 'tok', 'waba_id' => 'waba-'.$phoneNumberId],
            'status' => 'connected',
        ]);

        // El alta no se puede correr acá: `setUp` todavía no montó el `Http::fake()`
        // y le pegaría a Meta de verdad. Queda pendiente hasta `correrLaTarea()`.
        $this->altasPendientes[] = $meta;

        Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            // Distinto por tenant: `integrations` tiene un unico
            // (provider, account_identifier).
            'account_identifier' => 'duenio+'.$phoneNumberId.'@peluqueria.com', 'access_token' => 'ya29',
            'refresh_token' => '1//r', 'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        // `Tenant::created` ya siembra su `BusinessSetting` con los valores por
        // defecto (T-014): crear otra acá viola el único de `tenant_id`.

        return $tenant;
    }

    /**
     * Un turno agendado que arranca en `$inicio` **UTC**.
     *
     * ⚠️ **El `external_event_id` es distinto por turno desde el 2026-08-20.**
     * T-030 hizo único `(tenant_id, external_event_id)`, así que dos turnos de la
     * misma PyME con el mismo evento de Google ya no pueden existir: era un
     * montaje que representaba un estado imposible. Ninguna aserción de este
     * archivo mira el valor.
     *
     * @param  string  $estado  Para el caso del turno ya cancelado.
     */
    private function turno(
        CarbonImmutable $inicio,
        string $nombre = 'Ana',
        ?Tenant $tenant = null,
        string $estado = Booking::ESTADO_AGENDADO,
        ?string $eventId = null,
        string $telefono = self::TELEFONO,
    ): Booking {
        $tenant ??= $this->tenant;
        $eventId ??= 'evt_google_'.uniqid();

        return TenantContext::runAs($tenant->id, function () use ($tenant, $inicio, $nombre, $estado, $eventId, $telefono) {
            $conversacion = Conversation::create([
                'tenant_id' => $tenant->id,
                'user_phone' => $telefono,
                'current_state' => 'BOOKED',
                'state_version' => 4,
                'context_data' => ['nombre' => $nombre, 'servicio' => 'Corte de pelo'],
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
                'external_event_id' => $eventId,
                'client_name' => $nombre,
                'client_phone' => $telefono,
                'service_name' => 'Corte de pelo',
                'start_time' => $inicio,
                'end_time' => $inicio->addMinutes(30),
                'status' => $estado,
                'attendance' => Booking::ASISTENCIA_PENDIENTE,
            ]);
        });
    }

    // ------------------------------------------------------------- dobles

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: un segundo `fake()` para simular que
     * Google ya no tiene el evento no reemplazaría al primero, el doble del
     * error nunca se usaría y el test pasaría verde sin haber probado nada. Ya
     * pasó tres veces en T-026.
     *
     * @param  \Closure|null  $alConsultarElEvento  Qué contesta el `GET` a Calendar.
     */
    private function fakes(?\Closure $alConsultarElEvento = null): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => function (Request $request) use ($alConsultarElEvento) {
                if ($request->method() === 'DELETE') {
                    return Http::response([], 204);
                }

                if ($alConsultarElEvento !== null) {
                    return $alConsultarElEvento($request);
                }

                return Http::response([
                    'id' => 'evt_google_123',
                    'status' => 'confirmed',
                    'extendedProperties' => ['private' => ['origen' => 'agendallena']],
                ]);
            },

            // Wamid determinista: los tests del lado de la respuesta necesitan
            // saber qué `wamid` tuvo cada recordatorio sin adivinarlo.
            'graph.facebook.com/*' => function (Request $request) {
                /*
                 * T-050 · Crear una plantilla en la cuenta del tenant también es
                 * un POST a `graph.facebook.com`, y **no es un mensaje**: no
                 * mueve el contador de salientes ni consume un `wamid`. Meta las
                 * devuelve ya aprobadas para que estos tests sigan probando el
                 * envío y no el alta.
                 */
                if (str_contains($request->url(), 'message_templates')) {
                    return Http::response([
                        'id' => (string) random_int(1_000_000, 9_999_999),
                        'status' => 'APPROVED',
                        'category' => 'UTILITY',
                    ]);
                }

                $this->salientes++;

                $id = ($request->data()['type'] ?? null) === 'template'
                    ? 'wamid.RECORDATORIO_'.(++$this->recordatorios)
                    : 'wamid.OUT_'.$this->salientes;

                return Http::response(['messages' => [['id' => $id]]]);
            },
        ]);
    }

    // ---------------------------------------------------------- utilidades

    /** Lo que Meta **aceptó** como plantilla: los recordatorios que el cliente vio. */
    private function plantillasEnviadas(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com')
                && $res->successful()
                && ($req->data()['type'] ?? null) === 'template') {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    /** Los textos de los cuatro parámetros del cuerpo, en orden. */
    private function parametrosDelCuerpo(array $plantilla): array
    {
        foreach ($plantilla['template']['components'] ?? [] as $componente) {
            if (($componente['type'] ?? null) === 'body') {
                return array_map(
                    fn (array $p) => $p['text'] ?? null,
                    $componente['parameters'] ?? []
                );
            }
        }

        return [];
    }

    /** Los payloads de los botones, indexados por su posición. */
    private function botones(array $plantilla): array
    {
        $out = [];

        foreach ($plantilla['template']['components'] ?? [] as $componente) {
            if (($componente['type'] ?? null) !== 'button') {
                continue;
            }

            $out[(int) ($componente['index'] ?? 0)] = $componente['parameters'][0]['payload'] ?? null;
        }

        ksort($out);

        return $out;
    }

    private function registroDelRecordatorio(Booking $booking): ?object
    {
        return DB::table('notification_logs')
            ->where('booking_id', $booking->id)
            ->where('type', 'reminder_24h')
            ->first();
    }

    private function correrLaTarea(): void
    {
        $this->completarElAltaDeLasCuentas();

        $this->artisan(self::COMANDO)->assertSuccessful();
    }

    /**
     * T-050 · Deja a cada tenant con sus tres plantillas aprobadas en su cuenta.
     *
     * Va por el camino del producto —el alta— y no escribiendo el estado a mano:
     * así el montaje no fija dónde se guarda el registro de aprobación, que es
     * una decisión del implementador.
     *
     * Corre acá y no en `crearTenant()` porque necesita el `Http::fake()` puesto,
     * y los tests lo montan en su primera línea.
     */
    private function completarElAltaDeLasCuentas(): void
    {
        foreach ($this->altasPendientes as $meta) {
            TenantContext::runAs((string) $meta->tenant_id,
                fn () => app(AltaDeCuenta::class)->crearPlantillas($meta));
        }

        $this->altasPendientes = [];
    }

    private function ahora(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    // ----------------------------------------------- AC-10.1 · envío en ventana

    /**
     * AC-10.1 · El turno a 24 horas recibe la plantilla aprobada.
     *
     * Se afirma sobre el **payload que sale hacia Meta** y no sobre un método de
     * `MetaAdapter`: lo que el criterio promete es que el cliente reciba la
     * plantilla, no cómo se llame la función que la arma. Hoy `MetaAdapter` solo
     * sabe mandar texto e interactivos, así que esto exige el camino nuevo.
     */
    public function test_un_turno_a_veinticuatro_horas_recibe_la_plantilla_aprobada(): void
    {
        $this->fakes();
        $this->turno($this->ahora()->addHours(24));

        $this->correrLaTarea();

        $plantillas = $this->plantillasEnviadas();

        $this->assertCount(1, $plantillas, 'El turno a 24 horas no recibió su recordatorio.');
        $this->assertSame(self::PLANTILLA, $plantillas[0]['template']['name'] ?? null,
            'Se mandó otra plantilla: la única aprobada por Meta es '.self::PLANTILLA.'.');
        $this->assertSame(self::IDIOMA, $plantillas[0]['template']['language']['code'] ?? null,
            'El idioma no es el de la plantilla aprobada: Meta rechaza el envío entero.');
    }

    /**
     * AC-10.1 · Los tres botones, en el orden de la plantilla aprobada.
     *
     * El payload de cada botón **se manda en cada envío** y vuelve por webhook
     * (verificado contra Meta real el 2026-08-20). Sin él, la respuesta del
     * cliente llega sin nada que la ate a este turno.
     *
     * ⚠️ El texto de los botones lo fija la plantilla en el portal de Meta y no
     * viaja en el envío: lo único observable acá es el payload de cada índice.
     * Los índices son los del ticket: 0 Confirmar, 1 Re-agendar, 2 Cancelar.
     */
    public function test_el_recordatorio_lleva_los_tres_botones_con_su_payload_sellado(): void
    {
        $this->fakes();
        $this->turno($this->ahora()->addHours(24));

        $this->correrLaTarea();

        $plantillas = $this->plantillasEnviadas();
        $this->assertCount(1, $plantillas, 'No salió el recordatorio.');

        $botones = $this->botones($plantillas[0]);

        $this->assertSame([0, 1, 2], array_keys($botones),
            'La plantilla aprobada tiene tres botones quick reply: Confirmar, Re-agendar y Cancelar.');

        foreach ($botones as $indice => $payload) {
            $this->assertNotNull(IdSellado::leer($payload),
                "El botón {$indice} viaja con un payload que `IdSellado::leer` no puede parsear: "
                .'la respuesta del cliente va a llegar sin sello y no se va a poder resolver.');
        }

        $acciones = array_map(fn (string $p) => IdSellado::leer($p)->accion, $botones);

        $this->assertSame('confirmar', $acciones[0],
            'El botón 0 de la plantilla aprobada es Confirmar.');
        $this->assertCount(3, array_unique($acciones),
            'Dos botones mandan la misma acción: el bot no va a poder distinguir '
            .'confirmar de cancelar.');
    }

    /**
     * AC-10.1 · **Cuatro parámetros, ni uno más ni uno menos.**
     *
     * Verificado contra Meta: mandar uno de más o de menos hace que rechace el
     * mensaje entero. No es una preferencia de forma — es la diferencia entre
     * que el recordatorio llegue y que no llegue.
     */
    public function test_el_cuerpo_lleva_los_cuatro_parametros_de_la_plantilla_en_orden(): void
    {
        $this->fakes();
        $this->turno($this->ahora()->addHours(24), nombre: 'Ana');

        $this->correrLaTarea();

        $plantillas = $this->plantillasEnviadas();
        $this->assertCount(1, $plantillas, 'No salió el recordatorio.');

        $parametros = $this->parametrosDelCuerpo($plantillas[0]);

        $this->assertCount(4, $parametros,
            'La plantilla aprobada declara {{1}} a {{4}}: con otra cantidad Meta rechaza el envío entero.');

        $this->assertSame('Ana', $parametros[0], '{{1}} es el nombre del cliente.');
        $this->assertSame($this->tenant->name, $parametros[1], '{{2}} es el nombre del negocio.');
        $this->assertSame('Corte de pelo', $parametros[2], '{{3}} es el servicio.');
        $this->assertNotEmpty($parametros[3], '{{4}} es la fecha y hora del turno.');
    }

    // -------------------------------------------------- AC-07.4 · zona horaria

    /**
     * AC-07.4 · La hora va en la zona del tenant, **nunca en UTC**.
     *
     * Es el error más caro del producto porque es silencioso: no hay excepción,
     * no hay 500, solo un cliente que llega cinco horas tarde.
     *
     * El turno arranca a las 12:00 UTC del día siguiente, que en Bogotá son las
     * **07:00**. Que el texto diga "12:00" es exactamente el bug.
     */
    public function test_la_hora_del_recordatorio_va_en_la_zona_del_tenant_y_no_en_utc(): void
    {
        $this->fakes();
        // 2026-08-21 12:00 UTC = 2026-08-21 07:00 en Bogotá (UTC−5 todo el año).
        $this->turno($this->ahora()->addHours(24));

        $this->correrLaTarea();

        $plantillas = $this->plantillasEnviadas();
        $this->assertCount(1, $plantillas, 'No salió el recordatorio.');

        $cuando = $this->parametrosDelCuerpo($plantillas[0])[3] ?? '';

        $this->assertStringContainsString('07:00', $cuando,
            "El recordatorio dice «{$cuando}» y el turno es a las 07:00 de Bogotá.");
        $this->assertStringNotContainsString('12:00', $cuando,
            'El recordatorio mandó la hora UTC: el cliente va a llegar cinco horas tarde.');
    }

    /**
     * RNF-02 · Convertir para mostrar no puede corromper lo guardado.
     *
     * La otra mitad de la regla: la base guarda **UTC siempre**. Se lee crudo,
     * sin pasar por el cast, para que un modelo que "arregle" la zona escribiendo
     * hora local en `bookings` no pueda esconderse detrás de su propio accessor.
     */
    public function test_mandar_el_recordatorio_no_cambia_la_hora_guardada_en_la_base(): void
    {
        $this->fakes();
        $booking = $this->turno($this->ahora()->addHours(24));

        $this->correrLaTarea();

        $crudo = DB::table('bookings')->where('id', $booking->id)->value('start_time');

        $this->assertSame('2026-08-21 12:00:00', (string) $crudo,
            'La fila del turno quedó en hora local: la base guarda UTC (RNF-02).');
    }

    // ----------------------------------------------------- bordes de la ventana

    /**
     * AC-10.1 · Los dos bordes de adentro: 9 minutos antes y 9 después.
     *
     * La ventana se mueve con `setTestNow()`, no esperando.
     */
    public function test_los_turnos_apenas_dentro_de_la_ventana_reciben_el_recordatorio(): void
    {
        $this->fakes();

        $this->turno($this->ahora()->addHours(24)->subMinutes(9), nombre: 'Ana');
        $this->turno($this->ahora()->addHours(24)->addMinutes(9), nombre: 'Bruno', telefono: '5493764278403');

        $this->correrLaTarea();

        $avisados = array_map(fn (array $p) => $this->parametrosDelCuerpo($p)[0] ?? null, $this->plantillasEnviadas());

        sort($avisados);

        $this->assertSame(['Ana', 'Bruno'], $avisados,
            'La tolerancia de ±10 minutos deja afuera turnos que están adentro: '
            .'con la tarea corriendo cada 10 minutos, esos turnos no reciben recordatorio nunca.');
    }

    /**
     * AC-10.4 · Los dos bordes de afuera: 11 minutos antes y 11 después.
     *
     * Va con **testigo**: un turno en ventana que sí tiene que recibirlo. Sin él,
     * una implementación que no mande nada nunca pasaría este test sin haber
     * probado la ventana.
     */
    public function test_los_turnos_fuera_de_la_ventana_no_reciben_recordatorio(): void
    {
        $this->fakes();

        $this->turno($this->ahora()->addHours(24), nombre: 'Testigo');
        $this->turno($this->ahora()->addHours(24)->subMinutes(11), nombre: 'Temprano', telefono: '5493764278403');
        $this->turno($this->ahora()->addHours(24)->addMinutes(11), nombre: 'Tarde', telefono: '5493764278404');

        $this->correrLaTarea();

        $avisados = array_map(fn (array $p) => $this->parametrosDelCuerpo($p)[0] ?? null, $this->plantillasEnviadas());

        $this->assertSame(['Testigo'], $avisados,
            'Se mandó un recordatorio fuera de la ventana t-24h ± 10 min: '
            .'avisar a destiempo es peor que no avisar, porque el cliente lo lee como ruido.');
    }

    // ------------------------------------------------ AC-10.4 · sin duplicados

    /**
     * AC-10.4 · La tarea corre cada 10 minutos: dos corridas caen en la ventana.
     *
     * Sin candado, el cliente recibe el mismo recordatorio dos veces —o tres— y
     * la PyME paga cada plantilla. `notification_logs` con su único
     * `(booking_id, type)` es el candado que pide US-10.
     */
    public function test_el_recordatorio_no_se_manda_dos_veces(): void
    {
        $this->fakes();
        $booking = $this->turno($this->ahora()->addHours(24));

        $this->correrLaTarea();
        // Segunda pasada del scheduler, todavía dentro de la ventana.
        CarbonImmutable::setTestNow($this->ahora()->addMinutes(5));
        $this->correrLaTarea();

        $this->assertCount(1, $this->plantillasEnviadas(),
            'El cliente recibió el mismo recordatorio dos veces: la ventana dura 20 minutos '
            .'y la tarea corre cada 10.');

        $this->assertSame(1, DB::table('notification_logs')
            ->where('booking_id', $booking->id)->where('type', 'reminder_24h')->count(),
            'Hay más de un registro de recordatorio para el mismo turno: el único '
            .'(booking_id, type) es el candado y no se está usando.');
    }

    /**
     * AC-10.4 · El turno creado con menos de 24 horas de anticipación.
     *
     * Su ventana t-24h ya pasó antes de que el turno existiera. La tarea corre
     * varias veces hasta el turno mismo y **no manda nada**: mandar un
     * "recordatorio de mañana" un rato antes del turno es peor que el silencio.
     */
    public function test_un_turno_creado_con_menos_de_24_horas_de_anticipacion_no_recibe_recordatorio(): void
    {
        $this->fakes();

        $arranque = $this->ahora();
        $this->turno($arranque->addHours(5), nombre: 'Urgente');

        foreach ([0, 60, 270, 290, 299] as $minutos) {
            CarbonImmutable::setTestNow($arranque->addMinutes($minutos));
            $this->correrLaTarea();
        }

        $this->assertSame([], $this->plantillasEnviadas(),
            'Un turno reservado para dentro de 5 horas recibió un recordatorio de "mañana".');
    }

    /**
     * ⚠️ **No sale de los criterios: lo agrego y lo digo.**
     *
     * Ningún AC menciona los turnos cancelados, pero US-10 pide que la consulta
     * filtre por `status='scheduled'` y el índice `(tenant_id, start_time,
     * status)` existe para eso. Recordarle un turno a alguien que ya lo canceló
     * es una llamada al negocio de un cliente confundido.
     */
    public function test_un_turno_cancelado_no_recibe_recordatorio(): void
    {
        $this->fakes();

        $this->turno($this->ahora()->addHours(24), nombre: 'Testigo');
        $this->turno($this->ahora()->addHours(24), nombre: 'Cancelado',
            estado: Booking::ESTADO_CANCELADO, telefono: '5493764278403');

        $this->correrLaTarea();

        $avisados = array_map(fn (array $p) => $this->parametrosDelCuerpo($p)[0] ?? null, $this->plantillasEnviadas());

        $this->assertSame(['Testigo'], $avisados,
            'Se le recordó un turno a alguien que ya lo había cancelado.');
    }

    // ------------------------------------------------ el registro por turno

    /**
     * El envío queda registrado por turno, con su `wamid` y su tenant.
     *
     * El `wamid` no es decorativo: es lo que hace resoluble el `context.id` que
     * Meta devuelve cuando el cliente toca un botón, y sin él la respuesta llega
     * sin nada que la ate a este turno.
     */
    public function test_el_recordatorio_enviado_queda_registrado_con_su_wamid(): void
    {
        $this->fakes();
        $booking = $this->turno($this->ahora()->addHours(24));

        $this->correrLaTarea();

        $registro = $this->registroDelRecordatorio($booking);

        $this->assertNotNull($registro,
            'El recordatorio salió y no quedó registro: no hay forma de saber después '
            .'qué turnos quedaron sin avisar (US-24) ni de resolver la respuesta del cliente.');
        $this->assertSame('sent', $registro->status,
            'El recordatorio salió y el registro no dice que salió.');
        $this->assertSame('wamid.RECORDATORIO_1', $registro->whatsapp_message_id,
            'El registro no guarda el wamid del recordatorio: cuando el cliente toque un '
            .'botón, el `context.id` del webhook no va a poder resolverse a este turno.');
        // `tenant_id` es UUID: se compara como string. Un `(int)` sobre un UUID
        // devuelve 1 para todos y este test pasaría con la fuga presente.
        $this->assertSame($this->tenant->id, (string) $registro->tenant_id);
    }

    /**
     * RNF-01 · Cada PyME manda su recordatorio **desde su propio número**.
     *
     * Si el emisor saliera de una variable de entorno global, el recordatorio de
     * la peluquería le llegaría al cliente desde el número del consultorio.
     */
    public function test_cada_tenant_manda_su_recordatorio_desde_su_propio_numero(): void
    {
        $this->fakes();

        $otro = $this->crearTenant('Consultorio Norte', '9990000000001', 'America/Argentina/Buenos_Aires');

        $unoDeAca = $this->turno($this->ahora()->addHours(24), nombre: 'Ana');
        $unoDeAlla = $this->turno($this->ahora()->addHours(24), nombre: 'Bruno',
            tenant: $otro, telefono: '5493764278403');

        $this->correrLaTarea();

        $emisorPorCliente = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (($req->data()['type'] ?? null) !== 'template') {
                continue;
            }

            $nombre = $req->data()['template']['components'][0]['parameters'][0]['text'] ?? null;
            $emisorPorCliente[$nombre] = $req->url();
        }

        $this->assertStringContainsString('/'.self::PHONE_NUMBER_ID.'/messages', $emisorPorCliente['Ana'] ?? '',
            'El recordatorio de una PyME salió desde el número de otra.');
        $this->assertStringContainsString('/9990000000001/messages', $emisorPorCliente['Bruno'] ?? '',
            'El recordatorio de una PyME salió desde el número de otra.');

        $this->assertSame($this->tenant->id, (string) $this->registroDelRecordatorio($unoDeAca)->tenant_id);
        $this->assertSame($otro->id, (string) $this->registroDelRecordatorio($unoDeAlla)->tenant_id);
    }

    // ----------------------------------- recorte Pareto · el evento sigue vivo

    /**
     * **Recorte Pareto** · Antes de mandar, se pregunta si el evento sigue ahí.
     *
     * Cubre el hueco que deja diferir T-027 (sincronización inversa): si el
     * dueño borró el turno desde su propio Google Calendar, para él ese turno no
     * existe. Mandarle un recordatorio al cliente lo hace ir a un turno que
     * nadie va a atender.
     */
    public function test_si_el_evento_ya_no_esta_en_google_no_se_manda_el_recordatorio(): void
    {
        $this->fakes(alConsultarElEvento: fn () => Http::response(
            ['error' => ['code' => 404, 'message' => 'Not Found']], 404
        ));

        $this->turno($this->ahora()->addHours(24));

        $this->correrLaTarea();

        // Guarda: sin esto, un test que no consulta Google pasaría igual y no
        // habría probado nada del recorte.
        $consultas = Http::recorded(
            fn ($req, $res) => str_contains($req->url(), 'googleapis.com') && $res->status() === 404
        );

        $this->assertTrue($consultas->isNotEmpty(),
            'El doble que devuelve 404 nunca se consumió: no se consultó a Google, '
            .'así que este test no probó el recorte.');

        $this->assertSame([], $this->plantillasEnviadas(),
            'El dueño borró el turno de su calendario y al cliente igual le llegó el '
            .'recordatorio: va a ir a un turno que nadie va a atender.');
    }

    /**
     * **Recorte Pareto** · Y se pregunta **antes**, no después.
     *
     * Consultar después del envío no serviría de nada: el mensaje ya salió.
     */
    public function test_el_evento_se_verifica_antes_de_mandar_el_recordatorio(): void
    {
        $this->fakes();
        $this->turno($this->ahora()->addHours(24));

        $this->correrLaTarea();

        $consultaAGoogle = null;
        $envioAMeta = null;

        foreach (Http::recorded() as $i => [$req, $res]) {
            if ($consultaAGoogle === null && str_contains($req->url(), 'googleapis.com/calendar')) {
                $consultaAGoogle = $i;
            }

            if ($envioAMeta === null && ($req->data()['type'] ?? null) === 'template') {
                $envioAMeta = $i;
            }
        }

        $this->assertNotNull($consultaAGoogle,
            'No se consultó el evento en Google antes de mandar el recordatorio.');
        $this->assertNotNull($envioAMeta, 'No salió el recordatorio.');
        $this->assertLessThan($envioAMeta, $consultaAGoogle,
            'Se consultó a Google después de mandar el mensaje: el recordatorio ya salió, '
            .'verificarlo después no evita nada.');
    }

    // ------------------------------------------------- la tarea está programada

    /**
     * El alcance del ticket dice "cada 10 minutos".
     *
     * Un comando que existe y que nadie programa no manda ningún recordatorio, y
     * el síntoma en producción es idéntico al de un comando roto.
     */
    public function test_la_tarea_esta_programada_cada_diez_minutos(): void
    {
        $programadas = collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains((string) $evento->command, self::COMANDO));

        $this->assertCount(1, $programadas,
            'El comando '.self::COMANDO.' no está en el scheduler: no lo va a correr nadie.');

        $this->assertSame('*/10 * * * *', $programadas->first()->expression,
            'La tarea no corre cada 10 minutos: con la ventana de ±10 min, una frecuencia '
            .'menor deja turnos sin recordatorio.');
    }
}
