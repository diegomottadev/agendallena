<?php

namespace Tests\Feature;

use App\Meta\PlantillasDelTenant;
use App\Meta\PlantillasDeWhatsApp;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Google\CalendarioDeGoogle;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **Las tareas de plataforma no pueden consultar de a una fila.**
 *
 * ## Por qué importa, con los números del propio scheduler
 *
 * `recordatorios:enviar` corre **cada 10 minutos**, `plantillas:sincronizar` y
 * `agendamientos:conciliar` **cada 15**. Ninguna de las tres las dispara un
 * cliente: corren solas, sobre **toda la cartera**, para siempre. Una PyME con
 * la agenda llena mete diez turnos en la misma ventana de t-24h, y diez PyMEs
 * son cien. Si el costo de la corrida se multiplica por turno, el que paga no es
 * el que tiene el turno: es la base entera, cada diez minutos, y el síntoma es
 * *"la app anda lenta"* sin ninguna pantalla culpable.
 *
 * Es además la clase de defecto que **no se ve nunca en el piloto**: con un
 * tenant y dos turnos, siete consultas por turno son catorce consultas y nadie
 * se entera. Se ve el día que hay cartera, que es el día que no se puede tocar.
 *
 * ## Cómo se afirma un N+1, y por qué no es «hace menos de N consultas»
 *
 * Un tope fijo —*"la corrida hace 12 consultas"*— no dice nada sobre el defecto:
 * se rompe solo cuando alguien agrega una consulta legítima, y sigue verde con
 * el N+1 puesto si el número quedó holgado. Lo que define un N+1 es la
 * **pendiente**: que el costo **crezca con la cantidad de filas**.
 *
 * Por eso cada test de este archivo mide **dos veces** —con una fila y con
 * cinco— y afirma que la diferencia es **cero o acotada por una constante**,
 * nunca proporcional. `self::MARGEN` es ese colchón: una implementación que
 * resuelve el lote de una sola vez (`whereIn`) paga **lo mismo** con una fila
 * que con cinco, así que el margen no es un presupuesto, es tolerancia a que la
 * consulta batcheada se parta en dos.
 *
 * ## Qué se cuenta, y qué no
 *
 * Se cuentan **las lecturas de las tablas de contexto** —`tenants`,
 * `integrations`, `conversations`, `bookings` según el camino—, no el total de
 * consultas. No es para aflojar la afirmación sino para que diga la verdad:
 *
 * - **Las escrituras son por fila por definición.** El candado de
 *   `notification_logs` es una fila por turno (T-009); exigir que no crezca sería
 *   exigir que no se tome el candado.
 * - **`Message::firstOrCreate()` lee por `wamid`**, y ese `SELECT` también es por
 *   mensaje enviado: es la idempotencia del historial (T-048), no un N+1.
 *
 * Lo que **no** puede crecer por fila es la búsqueda del contexto: a qué PyME
 * pertenece el turno, con qué integración se manda y a qué conversación. Eso se
 * resuelve de a lote o no se resuelve.
 *
 * ⚠️ **Un N+1 de HTTP que este archivo no afirma.** Medido: cinco turnos de la
 * misma PyME en la misma ventana son **cinco peticiones a Google**, una por turno
 * (`eventoSigueExistiendo`), contra una cuota de terceros y sumando latencia real
 * —RNF-04 ya se mide en 2.361 ms p95—. Las cinco a Meta sí son inevitables: es un
 * mensaje por cliente. `ConciliarAgendamientos` resolvió el mismo
 * problema con **un listado por PyME**, así que hay precedente. No lo fijo en un
 * test porque cambiar de `GET` por turno a listado por PyME **cambia qué se
 * verifica** (el listado filtra por `origen`, el `GET` no), y eso es una decisión
 * de alcance, no una optimización.
 */
class ConsultasNMas1Test extends TestCase
{
    use RefreshDatabase;

    /**
     * Cuánto puede crecer el costo al pasar de una fila a cinco.
     *
     * **No es un presupuesto por fila**: resolver el lote de una sola vez cuesta
     * lo mismo con una que con cinco, así que lo esperado es cero. El uno es
     * tolerancia a que la consulta batcheada venga partida (un `whereIn` por
     * chunk, un `exists` separado), no permiso para consultar de a una.
     */
    private const MARGEN = 1;

    /**
     * Cuánto puede crecer por **PyME**, cuando el camino recorre PyMEs.
     *
     * Acá sí hay algo inevitable por tenant —el `runAs()`, la llamada a Google o
     * a Meta con **su** token—, pero eso es HTTP, no base: los datos de las cinco
     * PyMEs se leen de tres tablas y se pueden traer de a lote igual que los de
     * una. Se tolera holgado a propósito: lo que este archivo persigue es la
     * pendiente **por turno**, no discutirle al implementador si agrupa por
     * tenant o no.
     */
    private const MARGEN_POR_TENANT = 3;

    /** UTC−5 todo el año: no coincide con UTC ni con Buenos Aires ningún día. */
    private const TZ = 'America/Bogota';

    private const AHORA = '2026-08-20 12:00:00';

    /** ¿Estamos adentro de una medición? Fuera de eso, el montaje no se cuenta. */
    private bool $grabando = false;

    /** @var array<int,QueryExecuted> */
    private array $consultas = [];

    /**
     * Los calendarios del doble de Google, por token de acceso.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private array $calendarios = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC'));

        /*
         * El oyente se registra **una sola vez** y filtra por el flag. Registrarlo
         * al empezar cada medición dejaría oyentes acumulados —igual que
         * `Http::fake()`— y la segunda medición contaría cada consulta dos veces:
         * el N+1 se vería donde no está.
         */
        DB::listen(function (QueryExecuted $consulta) {
            if ($this->grabando) {
                $this->consultas[] = $consulta;
            }
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        $this->calendarios = [];
        parent::tearDown();
    }

    // --------------------------------------------------------- la medición

    /**
     * Las consultas que dispara **el camino**, sin las del montaje.
     *
     * Se empieza a escuchar justo antes de ejercerlo: `RefreshDatabase`, la
     * creación de los tenants y el sembrado de los turnos son consultas del test
     * y contarlas escondería la pendiente adentro del ruido.
     *
     * @return array<int,QueryExecuted>
     */
    private function midiendo(\Closure $camino): array
    {
        $this->consultas = [];
        $this->grabando = true;

        try {
            $camino();
        } finally {
            $this->grabando = false;
        }

        return $this->consultas;
    }

    /**
     * Las **lecturas** de esas tablas, que es lo que no puede crecer por fila.
     *
     * @param  array<int,QueryExecuted>  $consultas
     * @return array<int,string>
     */
    private function lecturasDe(array $consultas, string ...$tablas): array
    {
        $lecturas = [];

        foreach ($consultas as $consulta) {
            $sql = strtolower(trim($consulta->sql));

            if (! str_starts_with($sql, 'select')) {
                continue;
            }

            foreach ($tablas as $tabla) {
                if (str_contains($sql, '`'.$tabla.'`')) {
                    $lecturas[] = $consulta->sql;

                    break;
                }
            }
        }

        return $lecturas;
    }

    /**
     * Un resumen legible para el mensaje del assert: **qué** se consultó y
     * cuántas veces. Sin esto, "esperaba 3 y hubo 11" no dice dónde mirar.
     *
     * @param  array<int,string>  $sqls
     */
    private function detalle(array $sqls): string
    {
        $conteo = array_count_values($sqls);

        arsort($conteo);

        $lineas = [];

        foreach ($conteo as $sql => $veces) {
            $lineas[] = '  '.$veces.'x  '.$sql;
        }

        return implode("\n", $lineas);
    }

    // ------------------------------------------------------------- montaje

    /**
     * Una PyME lista para mandar: cuenta de WhatsApp cargada, plantillas
     * aprobadas en **su** cuenta y calendario conectado.
     *
     * @return array{0:Tenant,1:Integration}
     */
    private function pyme(string $slug, string $phoneNumberId, bool $conPlantillas = true): array
    {
        $tenant = Tenant::create([
            'name' => 'PyME '.$slug, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $meta = Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId, 'access_token' => 'meta-'.$slug,
            'settings' => ['verify_token' => 'tok-'.$slug, 'waba_id' => 'waba-'.$phoneNumberId],
            'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            // `integrations` tiene un único (provider, account_identifier), y el
            // token distingue el calendario de cada PyME en el doble.
            'account_identifier' => 'duenio+'.$phoneNumberId.'@pyme.com',
            'access_token' => $this->tokenDe($phoneNumberId),
            'refresh_token' => '1//r', 'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        if ($conPlantillas) {
            foreach (PlantillasDeWhatsApp::NOMBRES as $nombre) {
                PlantillasDelTenant::registrar($meta, $nombre, PlantillasDelTenant::APROBADA);
            }
        }

        // `Tenant::created` ya siembra su `BusinessSetting` (T-014).

        return [$tenant, $meta];
    }

    private function tokenDe(string $phoneNumberId): string
    {
        return 'ya29.'.$phoneNumberId;
    }

    private function googleDe(Tenant $tenant): Integration
    {
        return Integration::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
            ->firstOrFail();
    }

    /**
     * `$cuantos` turnos de esa PyME que caen justo en la ventana de t-24h.
     *
     * Cada uno con su conversación y su evento: `(tenant_id, external_event_id)`
     * es único desde T-030, y dos turnos del mismo cliente en el mismo horario
     * serían un montaje imposible.
     *
     * @return array<int,Booking>
     */
    private function turnosEnLaVentana(Tenant $tenant, int $cuantos, string $marca): array
    {
        $inicio = CarbonImmutable::now('UTC')->addHours(24);

        return TenantContext::runAs($tenant->id, function () use ($tenant, $cuantos, $marca, $inicio) {
            $google = $this->googleDe($tenant);
            $turnos = [];

            foreach (range(1, $cuantos) as $n) {
                $conversacion = Conversation::create([
                    'tenant_id' => $tenant->id,
                    'user_phone' => '54937642'.substr(md5($marca.$n), 0, 5),
                    'current_state' => 'BOOKED',
                    'state_version' => 4,
                    'context_data' => ['nombre' => 'Cliente '.$n, 'servicio' => 'Corte de pelo'],
                    'last_interaction_at' => CarbonImmutable::now('UTC'),
                ]);

                $turnos[] = Booking::create([
                    'tenant_id' => $tenant->id,
                    'conversation_id' => $conversacion->id,
                    'integration_id' => $google->id,
                    'external_event_id' => 'evt_'.$marca.'_'.$n,
                    'client_name' => 'Cliente '.$n,
                    'client_phone' => $conversacion->user_phone,
                    'service_name' => 'Corte de pelo',
                    'start_time' => $inicio,
                    'end_time' => $inicio->addMinutes(30),
                    'status' => Booking::ESTADO_AGENDADO,
                    'attendance' => Booking::ASISTENCIA_PENDIENTE,
                ]);
            }

            return $turnos;
        });
    }

    /** Un evento nuestro en el calendario de esa PyME, sin fila que lo reclame. */
    private function eventoEnElCalendario(Tenant $tenant, string $eventId): void
    {
        $inicio = CarbonImmutable::now('UTC')->addHours(20);

        $this->calendarios[$this->googleDe($tenant)->access_token][] = [
            'id' => $eventId,
            'status' => 'confirmed',
            'summary' => 'Corte de pelo',
            'start' => ['dateTime' => $inicio->toRfc3339String(), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $inicio->addMinutes(30)->toRfc3339String(), 'timeZone' => 'UTC'],
            'extendedProperties' => ['private' => [
                'origen' => CalendarioDeGoogle::MARCA_ORIGEN,
                'tenant_id' => (string) $tenant->id,
            ]],
        ];
    }

    // -------------------------------------------------------------- dobles

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: un segundo llamado no reemplaza al
     * primero, el doble nuevo nunca se usa y el test queda verde sin haber
     * probado nada. Ya hizo pasar tres tests de fallo en T-026. Por eso este
     * doble contesta según **la cuenta que nombra la petición**, y una sola vez
     * por test.
     */
    private function fakes(): void
    {
        Http::fake([
            // Listado del calendario (URL con query): devuelve lo que hay en el
            // calendario de **esa** cuenta, y sin `nextPageToken`, así que el
            // listado nunca queda truncado y el barrido inverso corre entero.
            'www.googleapis.com/calendar/v3/calendars/primary/events?*' => function (Request $request) {
                return Http::response([
                    'items' => array_values($this->calendarios[$this->tokenDeLaPeticion($request)] ?? []),
                ]);
            },

            // Un evento puntual: el `GET` por turno del recordatorio.
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response([
                'id' => 'evt',
                'status' => 'confirmed',
                'extendedProperties' => ['private' => ['origen' => CalendarioDeGoogle::MARCA_ORIGEN]],
            ]),

            'graph.facebook.com/*' => function (Request $request) {
                // Consultar el estado de las plantillas de una cuenta: las tres,
                // aprobadas. La sincronización no debería llegar acá cuando ya
                // están todas, y si llega el test lo puede ver.
                if (strtoupper($request->method()) === 'GET') {
                    return Http::response(['data' => array_map(fn (string $nombre) => [
                        'id' => (string) crc32($nombre),
                        'name' => $nombre,
                        'status' => PlantillasDelTenant::APROBADA,
                        'language' => 'es_AR',
                        'category' => 'UTILITY',
                    ], PlantillasDeWhatsApp::NOMBRES)]);
                }

                return Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]);
            },
        ]);
    }

    /**
     * De qué calendario habla esta petición.
     *
     * ⚠️ El token se usa **solo** como clave del doble: nunca se imprime ni se
     * afirma sobre él, ni parcialmente.
     */
    private function tokenDeLaPeticion(Request $request): string
    {
        return trim(str_replace('Bearer', '', $request->header('Authorization')[0] ?? ''));
    }

    // ------------------------------------------- recordatorios:enviar · turnos

    /**
     * **Cinco turnos de la misma PyME no pueden costar cinco búsquedas de
     * contexto.**
     *
     * Es el caso que más duele y el que más rápido llega: la peluquería con la
     * agenda llena mete cinco, ocho, quince turnos en la misma ventana de t-24h,
     * y la tarea corre cada diez minutos. Hoy cada turno vuelve a preguntar de
     * quién es, con qué manda y con quién habla — el mismo tenant, la misma
     * integración y datos que ya estaban en la mano.
     *
     * ## Cómo se aísla la pendiente
     *
     * Se mide la misma corrida dos veces con **la ventana corrida una hora**: el
     * turno de la primera medición queda afuera de la segunda, así que el segundo
     * número es el de cinco turnos limpios y no el de uno viejo más cinco.
     *
     * Se cuentan `tenants`, `integrations` y `conversations`: son las tres que se
     * pueden traer de a lote. El `INSERT` del candado y el `SELECT` por `wamid`
     * del historial son por turno **por definición** y quedan afuera del conteo.
     */
    public function test_los_recordatorios_no_consultan_el_contexto_una_vez_por_turno(): void
    {
        $this->fakes();

        [$pyme] = $this->pyme('peluqueria', '1053554814514902');

        $this->turnosEnLaVentana($pyme, 1, 'uno');
        $conUno = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('recordatorios:enviar')->assertSuccessful()),
            'tenants', 'integrations', 'conversations',
        );

        // La ventana se corre: el turno ya recordado queda afuera y la segunda
        // medición es de cinco turnos limpios, de la misma PyME.
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC')->addHour());

        $this->turnosEnLaVentana($pyme, 5, 'cinco');
        $conCinco = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('recordatorios:enviar')->assertSuccessful()),
            'tenants', 'integrations', 'conversations',
        );

        $this->assertNotEmpty($conUno,
            'La corrida con un turno no consultó ni el tenant ni la integración: el camino no se ejerció '
            .'y la comparación de abajo no prueba nada.');

        $this->assertLessThanOrEqual(count($conUno) + self::MARGEN, count($conCinco),
            'Cuatro turnos más de la **misma** PyME costaron '.(count($conCinco) - count($conUno))
            .' lecturas de contexto más: '.count($conUno).' con 1 turno contra '.count($conCinco)
            ." con 5. El contexto se busca por turno en vez de por lote, y esto corre cada 10 minutos "
            ."sobre toda la cartera. Las lecturas de la corrida de 5:\n".$this->detalle($conCinco));
    }

    /**
     * **Cinco PyMEs pueden costar algo más; cinco turnos, no.** El test tiene que
     * distinguir las dos cosas.
     *
     * Recorrer PyMEs tiene un costo inevitable *afuera* de la base —cada una se
     * consulta con su propio token contra Google y contra Meta—, pero **sus datos
     * salen de las mismas tres tablas** y se pueden traer de a lote igual que los
     * de una sola. Por eso acá el margen es por PyME y sigue siendo constante:
     * lo que se prohíbe es que el costo lo fije la cantidad de **filas**.
     *
     * El número de turnos se mantiene en uno por PyME a propósito: si creciera
     * junto con los tenants, este test y el anterior medirían lo mismo y ninguno
     * de los dos diría cuál de las dos cosas es la que crece.
     */
    public function test_los_recordatorios_no_consultan_el_contexto_una_vez_por_pyme_por_turno(): void
    {
        $this->fakes();

        [$primera] = $this->pyme('peluqueria', '1053554814514902');

        $this->turnosEnLaVentana($primera, 1, 'uno');
        $conUna = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('recordatorios:enviar')->assertSuccessful()),
            'tenants', 'integrations', 'conversations',
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC')->addHour());

        foreach (range(1, 5) as $n) {
            [$otra] = $this->pyme('pyme-'.$n, '77420199833'.str_pad((string) $n, 5, '0', STR_PAD_LEFT));
            $this->turnosEnLaVentana($otra, 1, 'pyme'.$n);
        }

        $conCinco = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('recordatorios:enviar')->assertSuccessful()),
            'tenants', 'integrations', 'conversations',
        );

        $techo = count($conUna) + self::MARGEN_POR_TENANT * 5;

        $this->assertLessThanOrEqual($techo, count($conCinco),
            'Cinco PyMEs con un turno cada una costaron '.count($conCinco).' lecturas de contexto contra '
            .count($conUna).' de una sola PyME, y el techo tolerado era '.$techo.'. Un costo por PyME es '
            .'inevitable (cada una tiene su token); lo que no puede es multiplicarse por turno. '
            ."Las lecturas de la corrida de 5 PyMEs:\n".$this->detalle($conCinco));
    }

    // ------------------------------------------------- PlantillasDelTenant

    /**
     * **El sistémico: preguntar dos cosas sobre la misma cuenta cuesta una sola
     * lectura.**
     *
     * `PlantillasDelTenant` relee la integración en **cada** llamada, y cuatro
     * métodos la llaman. `EnviarRecordatorios` pregunta primero si están todas
     * aprobadas y después cuáles faltan, sobre la **misma** instancia: dos
     * preguntas sobre el mismo estado, dos viajes a la base. Multiplicado por
     * turno, por corrida, cada diez minutos.
     *
     * Se afirma sobre las dos preguntas seguidas y no sobre un mecanismo: el test
     * no dice *cómo* se evita el segundo viaje —memo por instancia, resolución
     * previa, lo que sea—, dice que **no se paga dos veces por el mismo dato**.
     *
     * ⚠️ Ver el test siguiente: la relectura existe por una razón real y no se
     * puede tirar. Los dos tests se leen juntos.
     */
    public function test_dos_preguntas_seguidas_sobre_la_misma_cuenta_no_cuestan_dos_lecturas(): void
    {
        [, $meta] = $this->pyme('peluqueria', '1053554814514902');

        $lecturas = $this->lecturasDe($this->midiendo(function () use ($meta) {
            PlantillasDelTenant::todasAprobadas($meta);
            PlantillasDelTenant::pendientes($meta);
        }), 'integrations');

        $this->assertLessThanOrEqual(1, count($lecturas),
            'Preguntar `todasAprobadas()` y después `pendientes()` sobre la misma integración costó '
            .count($lecturas).' lecturas de `integrations`. Es el mismo estado, preguntado dos veces, '
            .'y este par corre por cada turno de cada corrida de `recordatorios:enviar`. '
            ."Las lecturas:\n".$this->detalle($lecturas));
    }

    /**
     * ⚠️ **Este test pasa hoy, y tiene que seguir pasando.** Es la red del
     * refactor, no un rojo.
     *
     * La relectura que el test de arriba quiere sacar del camino caliente existe
     * por una razón escrita en el propio docblock de `recargar()`: **el alta corre
     * fuera de un request**, y quien pregunta después suele tener en la mano una
     * instancia cargada *antes* de que el alta escribiera. Si el ahorro se hace
     * cacheando por `id` de integración y para siempre, la PyME recién dada de
     * alta queda con las plantillas eternamente `PENDING` para el proceso que ya
     * la tenía cargada: **no le sale ningún recordatorio**, que es exactamente el
     * fallo silencioso que T-050 vino a cerrar.
     *
     * Sin este test, "ahorrar la segunda lectura" y "romper el alta" se ven
     * iguales en la suite.
     */
    public function test_una_instancia_cargada_antes_del_alta_ve_lo_que_el_alta_escribio(): void
    {
        [, $meta] = $this->pyme('peluqueria', '1053554814514902', conPlantillas: false);

        // La instancia que quien consulta tiene en la mano: se cargó **antes**.
        $vieja = Integration::query()->findOrFail($meta->getKey());

        $this->assertFalse(PlantillasDelTenant::todasAprobadas($vieja),
            'Arranca sin plantillas aprobadas: si esto ya fuera true, lo de abajo no prueba nada.');

        // El alta, desde otro proceso: otra instancia del mismo registro.
        $delAlta = Integration::query()->findOrFail($meta->getKey());

        foreach (PlantillasDeWhatsApp::NOMBRES as $nombre) {
            PlantillasDelTenant::registrar($delAlta, $nombre, PlantillasDelTenant::APROBADA);
        }

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($vieja),
            'La instancia vieja no ve la aprobación que el alta acaba de escribir: la PyME queda trabada '
            .'en PENDING y no le sale ningún recordatorio. Es la razón por la que `recargar()` existe.');

        $this->assertSame([], PlantillasDelTenant::pendientes($vieja),
            '`pendientes()` sigue nombrando plantillas que ya están aprobadas: dos métodos de la misma '
            .'clase contestando distinto sobre el mismo estado.');
    }

    // ------------------------------------------- plantillas:sincronizar

    /**
     * **La sincronización no puede costar una lectura de `integrations` por
     * PyME.**
     *
     * Corre cada 15 minutos sobre **toda la cartera**, y en régimen no hace nada:
     * las PyMEs con las tres plantillas aprobadas se saltean sin llamar a Meta.
     * O sea que el costo de la corrida en el 99 % de los días es **puro N+1**:
     * dos lecturas por PyME —la integración y el estado de sus plantillas— para
     * no enterarse de nada.
     *
     * Por eso se mide justo ese caso, el de las PyMEs ya aprobadas: es el estado
     * en el que la tarea va a vivir, no el excepcional.
     */
    public function test_la_sincronizacion_no_lee_la_integracion_una_vez_por_pyme(): void
    {
        $this->fakes();

        $this->pyme('peluqueria', '1053554814514902');

        $conUna = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('plantillas:sincronizar')->assertSuccessful()),
            'integrations',
        );

        foreach (range(1, 4) as $n) {
            $this->pyme('pyme-'.$n, '77420199833'.str_pad((string) $n, 5, '0', STR_PAD_LEFT));
        }

        $conCinco = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('plantillas:sincronizar')->assertSuccessful()),
            'integrations',
        );

        $this->assertNotEmpty($conUna,
            'La corrida con una PyME no leyó `integrations`: el camino no se ejerció.');

        $this->assertLessThanOrEqual(count($conUna) + self::MARGEN, count($conCinco),
            'Cuatro PyMEs más costaron '.(count($conCinco) - count($conUna)).' lecturas de `integrations` '
            .'más: '.count($conUna).' con 1 y '.count($conCinco).' con 5. Las cinco están aprobadas y la '
            .'tarea las saltea a todas, así que esas lecturas no averiguan nada — y se pagan cada 15 '
            ."minutos. Las lecturas de la corrida de 5:\n".$this->detalle($conCinco));
    }

    // ------------------------------------------- agendamientos:conciliar

    /**
     * **La conciliación tampoco puede leer la integración una vez por PyME.**
     *
     * Cada 15 minutos, sobre toda la cartera, para averiguar en qué calendario
     * entrar. El listado a Google sí es por PyME —cada una tiene su calendario y
     * su token—, pero saber cuál es su integración no: eso sale de una tabla, de
     * a lote.
     */
    public function test_la_conciliacion_no_lee_la_integracion_una_vez_por_pyme(): void
    {
        $this->fakes();

        [$primera] = $this->pyme('peluqueria', '1053554814514902');
        $this->eventoEnElCalendario($primera, 'evt_huerfano_1');

        $conUna = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('agendamientos:conciliar')->assertSuccessful()),
            'integrations',
        );

        foreach (range(1, 4) as $n) {
            [$otra] = $this->pyme('pyme-'.$n, '77420199833'.str_pad((string) $n, 5, '0', STR_PAD_LEFT));
            $this->eventoEnElCalendario($otra, 'evt_huerfano_pyme_'.$n);
        }

        $conCinco = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('agendamientos:conciliar')->assertSuccessful()),
            'integrations',
        );

        $this->assertNotEmpty($conUna,
            'La corrida con una PyME no leyó `integrations`: el camino no se ejerció.');

        $this->assertLessThanOrEqual(count($conUna) + self::MARGEN, count($conCinco),
            'Cuatro PyMEs más costaron '.(count($conCinco) - count($conUna)).' lecturas de `integrations` '
            .'más: '.count($conUna).' con 1 y '.count($conCinco).' con 5. Con qué calendario entrar sale '
            ."de una tabla; se puede traer de a lote. Las lecturas de la corrida de 5:\n"
            .$this->detalle($conCinco));
    }

    /**
     * ⚠️ **Hallazgo de la medición, no de la auditoría por lectura: la
     * conciliación pregunta a la base una vez por *evento del calendario*.**
     *
     * `tieneSuFila()` corre adentro del `foreach ($eventos as $evento)`: un
     * `exists` por evento leído. La PyME con la agenda cargada tiene decenas de
     * eventos nuestros en la ventana de 62 días que la conciliación mira, así que
     * acá el multiplicador **no es la cantidad de PyMEs sino la cantidad de
     * turnos que la PyME agendó** — y crece con el éxito del producto, que es la
     * peor forma de crecer.
     *
     * Es el mismo N+1 que la propia clase ya evitó del lado de Google: el barrido
     * inverso se cruza contra el listado que ya trajo, en vez de pedir un `GET`
     * por turno. Del lado de la base todavía no.
     *
     * Se mide con **una sola PyME** a propósito: así el número no se puede
     * explicar por la cantidad de tenants y señala al bucle de eventos.
     */
    public function test_la_conciliacion_no_pregunta_a_la_base_una_vez_por_evento_del_calendario(): void
    {
        $this->fakes();

        [$pyme] = $this->pyme('peluqueria', '1053554814514902');
        $this->eventoEnElCalendario($pyme, 'evt_huerfano_1');

        $conUno = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('agendamientos:conciliar')->assertSuccessful()),
            'bookings',
        );

        foreach (range(2, 10) as $n) {
            $this->eventoEnElCalendario($pyme, 'evt_huerfano_'.$n);
        }

        $conDiez = $this->lecturasDe(
            $this->midiendo(fn () => $this->artisan('agendamientos:conciliar')->assertSuccessful()),
            'bookings',
        );

        $this->assertNotEmpty($conUno,
            'La corrida con un evento no consultó `bookings`: el camino no se ejerció y la comparación '
            .'de abajo no prueba nada.');

        $this->assertLessThanOrEqual(count($conUno) + self::MARGEN, count($conDiez),
            'Nueve eventos más en el calendario de **la misma** PyME costaron '
            .(count($conDiez) - count($conUno)).' consultas a `bookings` más: '.count($conUno)
            .' con 1 evento y '.count($conDiez).' con 10. Se pregunta fila por fila si el evento tiene '
            .'su turno, y la PyME con la agenda llena es la que más eventos tiene. '
            ."Las consultas de la corrida de 10:\n".$this->detalle($conDiez));
    }
}
