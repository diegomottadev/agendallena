<?php

namespace Tests\Feature;

use App\Console\Commands\ConciliarAgendamientos;
use App\Jobs\ProcessMessageJob;
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
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-030 · AC-22.1 · La conciliación entre el calendario y la base.
 *
 * El caso fácil ya está: con el proceso vivo, si el `INSERT` falla después de
 * crear el evento, `Fallback::liberarEvento()` lo borra. **Lo que falta es todo
 * lo demás**: el worker que muere entre las dos operaciones —no queda nadie que
 * compense—, y el borrado que Google rechaza, que hoy solo escribe
 * `EVENTO_HUERFANO_PERSISTE` en el log y ahí termina.
 *
 * En los dos casos el estado que queda es el mismo, y es el único que importa:
 * **un evento nuestro en el calendario de la PyME que ninguna fila de
 * `bookings` reclama.** Bloquea el horario para todos los demás, no genera
 * recordatorio y no se puede cancelar desde el chat. Es el hueco H-04.
 *
 * ## El alcance cambió
 *
 * El *Recorte Pareto* del ticket difería este job porque la decisión
 * *«compensar o conciliar»* estaba abierta. **Se cerró el 2026-08-20:
 * conciliar** — el evento no se borra en el acto, lo limpia una revisión
 * periódica. El job vuelve a estar en alcance.
 *
 * ## Decisiones que tuve que tomar para poder escribir el test
 *
 * ⚠️ **El nombre del comando lo elijo yo.** Ningún documento lo fija. Sigo la
 * convención de `conversaciones:expirar` y `recordatorios:enviar`:
 * `agendamientos:conciliar`.
 *
 * ⚠️ **«Reportar» lo interpreto como una línea de log estructurada** con
 * `codigo => CONCILIACION_DESALINEADO`, `tenant_id` y `external_event_id`, que
 * es la convención del proyecto y lo que T-039 sabe leer. Ningún documento dice
 * si además hay que avisarle a alguien, escribir una tabla o borrar el evento.
 * **El test no afirma que se borre**, justamente porque la decisión cerrada es
 * conciliar y no compensar: borrar quedaría para una revisión que nadie definió.
 *
 * ## La cadencia y la dirección inversa se decidieron el 2026-08-21
 *
 * Las dos notas que seguían abiertas acá —«la cadencia no se afirma» y «solo se
 * prueba una de las dos direcciones»— dejaron de estar abiertas. Ver
 * `plan-for-diego/decisiones-tomadas.md` § 7 y § 9. Los tests de este archivo
 * las afirman ahora; los ⚠️ que quedan son los que siguen sin decidir.
 *
 * **§ 7 · Cada 15 minutos.** Acota a un cuarto de hora el tiempo en que un
 * horario puede quedar ocupado sin turno real detrás, que es la contracara de
 * haber elegido conciliar en vez de compensar. La frecuencia vive como
 * constante con nombre: moverla a 5 o a 60 tiene que ser una línea.
 *
 * **§ 9 · La misma corrida mira las dos direcciones.** Además del evento sin
 * fila, el turno vivo cuyo evento el dueño borró a mano desde su propio Google
 * Calendar. Ese turno hoy sigue vivo, ocupa la agenda y **cuenta para la tasa de
 * ausentismo**.
 *
 * ⚠️ **La ventana de tiempo que se consulta tampoco se afirma.** El doble de
 * Google ignora `timeMin`/`timeMax` a propósito: el ticket no dice hasta dónde
 * mirar hacia atrás, y un test que lo fijara estaría inventándolo.
 *
 * ⚠️ **Cancelado y no ausente.** La decisión § 9 dice que el turno cuyo evento
 * desapareció se marca **cancelado**. `status` y `attendance` son columnas
 * ortogonales a propósito (T-036): un turno puede estar `confirmed` y `no_show`
 * a la vez. Un turno que el dueño borró de su calendario **no es un cliente que
 * no vino**: nadie lo esperó. Si la conciliación tocara `attendance`, la tasa de
 * ausentismo —el número con el que se vende— contaría como plantones decisiones
 * administrativas del propio dueño. Por eso hay un test que afirma que
 * `attendance` queda intacta, y no es redundante con el que afirma el `status`.
 *
 * ⚠️ **El nombre del código del reporte inverso lo elijo yo**:
 * `CONCILIACION_TURNO_SIN_EVENTO`, distinto del huérfano, porque son dos
 * situaciones distintas y quien lea el log tiene que poder separarlas.
 *
 * ⚠️ **Solo se cancelan turnos cuyo inicio todavía no pasó.** Decisión del
 * team-lead del 2026-08-21, **pendiente de que Diego la ratifique**: § 9 no la
 * dice. Los dos daños que § 9 nombra —ocupar la agenda y contar para el
 * ausentismo— no aplican hacia atrás, y cancelar un turno pasado ya marcado lo
 * saca de la métrica en silencio. Ver los dos tests del final de la sección § 9.
 */
class ConciliacionDeAgendamientoTest extends TestCase
{
    use RefreshDatabase;

    /** ⚠️ Elección mía: ningún documento nombra el comando. Ver la nota de clase. */
    private const COMANDO = 'agendamientos:conciliar';

    /** ⚠️ Elección mía: qué significa «reportar». Ver la nota de clase. */
    private const CODIGO = 'CONCILIACION_DESALINEADO';

    /** § 9 · La dirección inversa: el turno vivo cuyo evento ya no está. */
    private const CODIGO_SIN_EVENTO = 'CONCILIACION_TURNO_SIN_EVENTO';

    /**
     * § 9 · El barrido inverso **no corrió** para esta PyME.
     *
     * ⚠️ El código lo elijo yo, y **a propósito no reuso
     * `CONCILIACION_LISTADO_TRUNCADO`**, que ya existe: ese dice que el listado
     * vino incompleto, que es un hecho sobre la lectura. Este dice que a esa PyME
     * **no se le está barriendo la dirección inversa**, que es un hecho sobre el
     * servicio y es lo que el operador necesita accionar. Reusar el viejo además
     * haría pasar este test sin arreglar nada.
     */
    private const CODIGO_INVERSA_OMITIDA = 'CONCILIACION_INVERSA_OMITIDA';

    private const PHONE_NUMBER_ID = '1053554814514902';

    /**
     * **UTC−5 todo el año.**
     *
     * No es Buenos Aires a propósito: la suite corre en UTC, y Santiago y BA
     * comparten offset parte del año —eso ya hizo pasar por casualidad un test
     * de husos en T-031—. Bogotá no coincide con UTC ningún día del año.
     */
    private const TZ = 'America/Bogota';

    private Tenant $tenant;

    /**
     * El calendario del doble, **uno por cuenta de Google**.
     *
     * `[access_token => [id de evento => evento]]`. Se indexa por el token
     * porque es lo único que viaja en la petición y porque es la verdad del
     * mundo real: cada tenant conectó su propia cuenta, y cada cuenta tiene su
     * propio calendario. Un doble con un calendario único haría pasar un test
     * de aislamiento sin aislamiento.
     *
     * @var array<string,array<string,array<string,mixed>>>
     */
    private array $calendarios = [];

    /** Lo que la conciliación reportó, en orden. @var array<int,array<string,mixed>> */
    private array $reportado = [];

    /**
     * Lo reportado por la **dirección inversa** (§ 9), en orden.
     *
     * Va en su propia lista para que los tests de huérfanos no se contaminen: si
     * las dos direcciones cayeran en el mismo array, un comando que reportara
     * todo dos veces pasaría los tests negativos que existen justamente para
     * impedirlo.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $reportadoSinEvento = [];

    /** Las PyMEs cuyo barrido inverso se frenó. @var array<int,array<string,mixed>> */
    private array $inversaOmitida = [];

    /** Cuántos listados —no páginas— ya sirvió el doble. */
    private int $listadosServidos = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC'));
        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        $this->tenant = $this->crearTenant('Peluquería Sur', self::PHONE_NUMBER_ID);

        Log::listen(function ($mensaje) {
            $codigo = $mensaje->context['codigo'] ?? null;

            if ($codigo === self::CODIGO) {
                $this->reportado[] = $mensaje->context;
            }

            if ($codigo === self::CODIGO_SIN_EVENTO) {
                $this->reportadoSinEvento[] = $mensaje->context;
            }

            if ($codigo === self::CODIGO_INVERSA_OMITIDA) {
                $this->inversaOmitida[] = $mensaje->context;
            }
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------- montaje

    private function crearTenant(string $nombre, string $phoneNumberId): Tenant
    {
        $tenant = Tenant::create([
            'name' => $nombre, 'slug' => 'piloto-'.$phoneNumberId,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId, 'access_token' => 'meta',
            'settings' => ['verify_token' => 'tok'], 'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            // `integrations` tiene un único (provider, account_identifier), y el
            // token distingue el calendario de cada tenant en el doble.
            'account_identifier' => 'duenio+'.$phoneNumberId.'@peluqueria.com',
            'access_token' => $this->tokenDe($phoneNumberId),
            'refresh_token' => '1//r', 'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        // `Tenant::created` ya siembra su `BusinessSetting` (T-014).

        return $tenant;
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
     * Pone un evento en el calendario de un tenant, **sin fila en `bookings`**.
     *
     * Es el estado que deja un worker que murió entre el `POST` a Google y el
     * `INSERT`, y también el que deja un borrado compensatorio que Google
     * rechazó. No hace falta matar un proceso para llegar acá: lo que define el
     * caso no es cómo se llegó, sino qué quedó — un evento nuestro que nadie
     * reclama. El camino largo, con el flujo real, está en el último test.
     *
     * @param  string|null  $marcaDeTenant  El `tenant_id` que lleva el evento en
     *   sus `extendedProperties`. `null` = un evento que no creamos nosotros.
     */
    private function eventoEnElCalendario(
        Tenant $tenant,
        string $eventId,
        ?string $marcaDeTenant = null,
        bool $nuestro = true,
        string $resumen = 'Corte de pelo · María',
    ): void {
        $privadas = [];

        if ($nuestro) {
            $privadas = [
                'origen' => \App\Services\Google\CalendarioDeGoogle::MARCA_ORIGEN,
                'tenant_id' => $marcaDeTenant ?? $tenant->id,
            ];
        }

        $inicio = CarbonImmutable::now('UTC')->addHours(20);

        $this->calendarios[$this->googleDe($tenant)->access_token][$eventId] = [
            'id' => $eventId,
            'status' => 'confirmed',
            'summary' => $resumen,
            'start' => ['dateTime' => $inicio->toRfc3339String(), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $inicio->addMinutes(30)->toRfc3339String(), 'timeZone' => 'UTC'],
            'extendedProperties' => ['private' => $privadas],
        ];
    }

    /**
     * Una fila de `bookings` que reclama ese evento.
     *
     * @param  string|null  $estado  `null` = el estado con el que nace un turno.
     * @param  CarbonImmutable|null  $cuando  `null` = dentro de la ventana que la
     *   conciliación mira. Se pasa explícito solo para probar los bordes.
     * @param  string|null  $asistencia  `null` = todavía no se marcó.
     */
    private function turnoEnLaBase(
        Tenant $tenant,
        string $eventId,
        string $nombre = 'María',
        ?string $estado = null,
        ?CarbonImmutable $cuando = null,
        ?string $asistencia = null,
    ): Booking {
        $inicio = $cuando ?? CarbonImmutable::now('UTC')->addHours(20);

        return TenantContext::runAs($tenant->id, fn () => Booking::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => null,
            'integration_id' => $this->googleDe($tenant)->id,
            'external_event_id' => $eventId,
            'client_name' => $nombre,
            'client_phone' => '5493764278402',
            'service_name' => 'Corte de pelo',
            'start_time' => $inicio,
            'end_time' => $inicio->addMinutes(30),
            'status' => $estado ?? Booking::ESTADO_AGENDADO,
            'attendance' => $asistencia ?? Booking::ASISTENCIA_PENDIENTE,
        ]));
    }

    /**
     * La fila, **leída cruda de la base** y sin ningún scope de por medio.
     *
     * Se lee con `DB::table()` a propósito: el Global Scope de `BelongsToTenant`
     * podría esconder justo la fila que el test quiere mirar, y entonces una fuga
     * entre tenants se vería como "no pasó nada". Acá el test quiere ver todo.
     */
    private function filaCruda(Tenant $tenant, string $eventId): ?object
    {
        return DB::table('bookings')
            ->where('tenant_id', $tenant->id)
            ->where('external_event_id', $eventId)
            ->first();
    }

    // ------------------------------------------------------------- el doble

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: un segundo `fake()` no reemplaza al
     * primero, el doble del error nunca se usa y el test pasa contra la
     * respuesta feliz. Ya pasó tres veces en T-026.
     *
     * El doble se comporta como Google y no como una respuesta fija: el listado
     * devuelve **lo que hay en el calendario de esa cuenta**, el `POST` agrega y
     * el `DELETE` saca. Así el último test puede crear el huérfano recorriendo
     * el flujo de verdad, en vez de que yo declare por decreto que existe.
     *
     * @param  bool  $elBorradoFalla  Google rechaza el `DELETE`: el evento queda.
     * @param  string|null  $tokenCaido  El calendario de **esa** cuenta contesta
     *   500 a todo. Se apunta a una sola cuenta y no a Google entero para que un
     *   test pueda tener, en la misma corrida, una PyME que no se pudo consultar
     *   y otra que sí.
     * @param  string|null  $tokenPatologico  El calendario de **esa** cuenta no
     *   termina nunca: **toda** página vuelve con `nextPageToken`. Es la PyME
     *   con muchísimos más eventos nuestros en la ventana de 62 días que páginas
     *   está dispuesto a pedir `eventosPropios()`, así que ni siguiendo la
     *   paginación hasta el tope se llega al final. Es el único caso en el que
     *   el listado queda truncado: **un `nextPageToken` suelto ya no alcanza**,
     *   porque ahora se lo sigue.
     * @param  int  $listadosPatologicos  Cuántos listados salen patológicos
     *   antes de volver a la normalidad. Sirve para que un mismo test compare la
     *   corrida truncada con la completa **sin un segundo `Http::fake()`**, que
     *   acumularía stubs en vez de reemplazarlos. Se cuenta por **listado** y no
     *   por petición: la primera página de cada listado es la que no trae
     *   `pageToken`.
     */
    private function fakeDeGoogle(
        bool $elBorradoFalla = false,
        ?string $tokenCaido = null,
        ?string $tokenPatologico = null,
        int $listadosPatologicos = PHP_INT_MAX,
    ): void {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),

            // Listado. `Str::start($url, '*')` no agrega comodín al final, así
            // que este patrón solo matchea la URL con query.
            'www.googleapis.com/calendar/v3/calendars/primary/events?*' => function (Request $request) use ($tokenCaido, $tokenPatologico, $listadosPatologicos) {
                if ($tokenCaido !== null && $this->tokenDe_($request) === $tokenCaido) {
                    return Http::response(['error' => ['message' => 'backendError']], 500);
                }

                $primeraPagina = $this->parametroDeLaQuery($request, 'pageToken') === null;

                if ($primeraPagina) {
                    $this->listadosServidos++;
                }

                $patologico = $tokenPatologico !== null
                    && $this->tokenDe_($request) === $tokenPatologico
                    && $this->listadosServidos <= $listadosPatologicos;

                /*
                 * La página que no es la primera solo llega acá si la
                 * implementación siguió el `nextPageToken`, y en el caso
                 * patológico eso pasa hasta agotar el tope. Devuelve los mismos
                 * eventos que la primera: para lo que estos tests afirman —que
                 * el barrido inverso se frena— el contenido no juega, y las
                 * páginas con contenido propio viven en
                 * `PaginacionDelCalendarioTest`.
                 */
                $cuerpo = [
                    'items' => array_values(array_filter(
                        $this->calendarioDe($request),
                        fn (array $evento) => $this->cumpleLosFiltros($evento, $request),
                    )),
                ];

                if ($patologico) {
                    // Google dice «hay más» **siempre**: por más que se pagine,
                    // el calendario nunca se termina de leer. Lo que falta puede
                    // ser cualquier evento, incluido el del turno que este test
                    // tiene vivo.
                    $cuerpo['nextPageToken'] = 'CiAKGjBpNDd2Nmp2Zml2cXRwYjBpOXA';
                }

                return Http::response($cuerpo);
            },

            // Creación (URL exacta, sin query).
            'www.googleapis.com/calendar/v3/calendars/primary/events' => function (Request $request) {
                $id = $request->data()['id'] ?? 'evt_google_'.(count($this->calendarioDe($request)) + 1);

                $evento = array_merge($request->data(), ['id' => $id, 'status' => 'confirmed']);

                $this->calendarios[$this->tokenDe_($request)][$id] = $evento;

                return Http::response($evento);
            },

            // Un evento puntual: `GET` y `DELETE`.
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => function (Request $request) use ($elBorradoFalla, $tokenCaido) {
                $id = urldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
                $token = $this->tokenDe_($request);

                if ($tokenCaido !== null && $token === $tokenCaido) {
                    return Http::response(['error' => ['message' => 'backendError']], 500);
                }

                if ($request->method() === 'DELETE') {
                    if ($elBorradoFalla) {
                        // El evento **no** se saca del calendario: es justo el
                        // caso que deja el huérfano vivo.
                        return Http::response(['error' => ['message' => 'backendError']], 500);
                    }

                    unset($this->calendarios[$token][$id]);

                    return Http::response([], 204);
                }

                return isset($this->calendarios[$token][$id])
                    ? Http::response($this->calendarios[$token][$id])
                    : Http::response(['error' => ['message' => 'Not Found']], 404);
            },

            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);
    }

    private function tokenDe_(Request $request): string
    {
        $autorizacion = $request->header('Authorization')[0] ?? '';

        // ⚠️ Nunca se afirma sobre el token ni se imprime: solo se usa como
        // clave para saber de qué calendario habla esta petición.
        return trim(str_replace('Bearer', '', $autorizacion));
    }

    /** @return array<string,array<string,mixed>> */
    private function calendarioDe(Request $request): array
    {
        return $this->calendarios[$this->tokenDe_($request)] ?? [];
    }

    /**
     * Aplica los `privateExtendedProperty` de la consulta, como hace Google.
     *
     * Se honran de verdad —y no se devuelve todo— porque si no, una
     * implementación que filtre bien del lado del servidor se vería castigada:
     * el doble le mandaría eventos que Google nunca le habría mandado y el test
     * la marcaría en rojo por un falso positivo que no es suyo.
     */
    private function cumpleLosFiltros(array $evento, Request $request): bool
    {
        $privadas = $evento['extendedProperties']['private'] ?? [];

        foreach ($this->filtrosPrivados($request) as [$clave, $valor]) {
            if (($privadas[$clave] ?? null) !== $valor) {
                return false;
            }
        }

        return true;
    }

    /**
     * Un parámetro suelto de la query. `null` si no vino.
     *
     * Se parsea a mano por lo mismo que `filtrosPrivados()`: `parse_str()`
     * colapsa las claves repetidas de `privateExtendedProperty`.
     */
    private function parametroDeLaQuery(Request $request, string $buscada): ?string
    {
        foreach (explode('&', (string) parse_url($request->url(), PHP_URL_QUERY)) as $par) {
            [$clave, $valor] = array_pad(explode('=', $par, 2), 2, '');

            if (urldecode($clave) === $buscada) {
                return urldecode($valor);
            }
        }

        return null;
    }

    /**
     * Los `privateExtendedProperty=clave=valor` de la query, que se repiten.
     *
     * Se parsea a mano: `parse_str()` colapsa las claves repetidas y se quedaría
     * con la última, que es la mitad del filtro.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function filtrosPrivados(Request $request): array
    {
        $out = [];

        foreach (explode('&', (string) parse_url($request->url(), PHP_URL_QUERY)) as $par) {
            [$clave, $valor] = array_pad(explode('=', $par, 2), 2, '');

            if (urldecode($clave) !== 'privateExtendedProperty') {
                continue;
            }

            [$k, $v] = array_pad(explode('=', urldecode($valor), 2), 2, '');
            $out[] = [$k, $v];
        }

        return $out;
    }

    // ---------------------------------------------------------- utilidades

    private function conciliar(): void
    {
        // La tarea corre **fuera de un request**: no hereda ningún tenant. Si la
        // implementación se olvidara de establecerlo, el Global Scope de
        // `BelongsToTenant` lanza y el comando falla acá mismo.
        TenantContext::forget();

        $this->artisan(self::COMANDO)->assertSuccessful();
    }

    /** Los `external_event_id` reportados como desalineados. */
    private function eventosReportados(): array
    {
        return array_map(fn (array $c) => $c['external_event_id'] ?? null, $this->reportado);
    }

    /** § 9 · Los `external_event_id` reportados como turnos sin evento. */
    private function turnosSinEventoReportados(): array
    {
        return array_map(fn (array $c) => $c['external_event_id'] ?? null, $this->reportadoSinEvento);
    }

    // ------------------------------------------------- detecta el huérfano

    /**
     * AC-22.1 · El evento que ninguna fila reclama **se detecta y se reporta**.
     *
     * Es el estado que deja el worker que murió entre el `POST` y el `INSERT`:
     * nadie compensó, y el horario está bloqueado en el calendario de la PyME
     * por un turno que el sistema no conoce.
     */
    public function test_reporta_el_evento_huerfano_que_ninguna_fila_reclama(): void
    {
        $this->fakeDeGoogle();
        $this->eventoEnElCalendario($this->tenant, 'evt_huerfano');

        $this->conciliar();

        $this->assertContains('evt_huerfano', $this->eventosReportados(),
            'La conciliación no vio el evento huérfano: el horario queda bloqueado para siempre '
            .'por un turno que nunca existió (H-04).');
    }

    /** El reporte trae con qué actuar: de qué PyME es el evento. */
    public function test_el_reporte_dice_de_que_tenant_es_el_evento(): void
    {
        $this->fakeDeGoogle();
        $this->eventoEnElCalendario($this->tenant, 'evt_huerfano');

        $this->conciliar();

        $this->assertCount(1, $this->reportado);

        // `tenant_id` es UUID: se compara como string. Un `(int)` sobre un UUID
        // devuelve 1 para todos y esta afirmación pasaría siempre.
        $this->assertSame($this->tenant->id, (string) ($this->reportado[0]['tenant_id'] ?? ''),
            'El reporte no identifica al tenant: un desalineado sin dueño no se puede resolver.');
    }

    // --------------------------------------------------- no inventa alarmas

    /**
     * Un turno bien agendado **no** es un desalineado.
     *
     * Sin este test, un comando que reporte todo lo que ve pasaría el anterior.
     */
    public function test_no_reporta_un_turno_que_tiene_su_fila(): void
    {
        $this->fakeDeGoogle();
        $this->eventoEnElCalendario($this->tenant, 'evt_ok');
        $this->turnoEnLaBase($this->tenant, 'evt_ok');

        $this->conciliar();

        $this->assertSame([], $this->eventosReportados(),
            'Reportó como desalineado un turno que está bien en los dos lados.');
    }

    /**
     * Un evento que **cargó la PyME a mano** no es nuestro y no se toca.
     *
     * Es el test que más importa de los tres negativos. El dueño usa su
     * calendario para todo —el dentista, el asado, el turno que cargó por
     * teléfono—, y ninguno de esos tiene fila en `bookings`. Un comando que los
     * cuente como desalineados reportaría la agenda entera del dueño, y si
     * alguna vez la conciliación pasa de reportar a limpiar, se la borraría.
     *
     * Por eso `crearEvento()` marca `origen: agendallena` en
     * `extendedProperties.private` (T-026), y por eso existe
     * `CalendarioDeGoogle::loCreamosNosotros()`.
     */
    public function test_no_reporta_un_evento_que_cargo_la_pyme_a_mano(): void
    {
        $this->fakeDeGoogle();
        $this->eventoEnElCalendario($this->tenant, 'evt_del_duenio', nuestro: false, resumen: 'Dentista');

        $this->conciliar();

        $this->assertSame([], $this->eventosReportados(),
            'Reportó un evento que la PyME cargó a mano: la conciliación no puede opinar '
            .'sobre la agenda propia del dueño.');
    }

    // ---------------------------------------------------- aislamiento (RNF-01)

    /**
     * RNF-01 · Dos tenants, dos calendarios, **y la fila de uno no tapa el
     * huérfano del otro**.
     *
     * El `external_event_id` compartido no es rebuscado: Google no garantiza
     * unicidad entre cuentas distintas, y una clave de idempotencia mal derivada
     * —de la hora del turno, por ejemplo— produce el mismo `id` en dos PyMEs que
     * abren a la misma hora.
     *
     * Si la conciliación buscara la fila con `withoutTenantScope()` y sin filtrar
     * por tenant, encontraría la de *Consultorio Norte* y daría por sano el
     * huérfano de *Peluquería Sur*: el desalineado quedaría invisible **por una
     * fuga entre tenants**, que es exactamente lo que RNF-01 existe para impedir.
     */
    public function test_la_fila_de_un_tenant_no_tapa_el_huerfano_de_otro(): void
    {
        $this->fakeDeGoogle();

        $otro = $this->crearTenant('Consultorio Norte', '1053554814514903');

        // El mismo id de evento en las dos cuentas de Google.
        $this->eventoEnElCalendario($this->tenant, 'evt_compartido');
        $this->eventoEnElCalendario($otro, 'evt_compartido');

        // Pero solo el segundo tiene su fila.
        $this->turnoEnLaBase($otro, 'evt_compartido', 'Ana');

        $this->conciliar();

        $this->assertCount(1, $this->reportado,
            'La conciliación cruzó los datos de dos PyMEs: o tapó el huérfano de una con la '
            .'fila de la otra, o reportó como desalineado un turno que está bien.');

        $this->assertSame($this->tenant->id, (string) ($this->reportado[0]['tenant_id'] ?? ''),
            'El desalineado reportado es del tenant equivocado.');
    }

    /** Cada tenant se revisa: no se corta en el primero. */
    public function test_revisa_los_desalineados_de_todos_los_tenants(): void
    {
        $this->fakeDeGoogle();

        $otro = $this->crearTenant('Consultorio Norte', '1053554814514903');

        $this->eventoEnElCalendario($this->tenant, 'evt_sur');
        $this->eventoEnElCalendario($otro, 'evt_norte');

        $this->conciliar();

        $reportados = $this->eventosReportados();

        $this->assertContains('evt_sur', $reportados);
        $this->assertContains('evt_norte', $reportados,
            'El segundo tenant no se revisó: la conciliación se cortó en el primero.');

        $porTenant = [];
        foreach ($this->reportado as $c) {
            $porTenant[(string) $c['external_event_id']] = (string) $c['tenant_id'];
        }

        $this->assertSame($this->tenant->id, $porTenant['evt_sur']);
        $this->assertSame($otro->id, $porTenant['evt_norte']);
    }

    /**
     * § 9 · RNF-03 · **Google caído no cancela turnos.**
     *
     * Es el modo de falla más caro que puede tener la dirección inversa, y el más
     * fácil de escribir sin darse cuenta: «no pude verificar» **no es** «el evento
     * no está». Si la conciliación tratara las dos cosas igual, media hora de
     * Google devolviendo 500 le cancelaría a la PyME todos los turnos de los
     * próximos dos meses, en dos corridas, sin que nadie toque nada. Y los
     * clientes ya tienen su confirmación en el celular.
     *
     * ⚠️ La asimetría no es teórica: `eventoSigueExistiendo()` está documentado
     * para devolver `false` ante cualquier respuesta que no se pueda interpretar,
     * porque para **no mandar un recordatorio** equivocarse es barato. Para
     * **cancelar** no lo es. Este test fija esa diferencia.
     *
     * El canario es Norte, cuyo calendario sí responde: sin él, este test lo pasa
     * un comando que no hace nada.
     */
    public function test_un_fallo_de_google_no_cancela_los_turnos_de_esa_pyme(): void
    {
        $this->fakeDeGoogle(tokenCaido: $this->tokenDe(self::PHONE_NUMBER_ID));

        $otro = $this->crearTenant('Consultorio Norte', '1053554814514903');

        // Sur: el turno está sano, pero su calendario no se puede consultar.
        $this->eventoEnElCalendario($this->tenant, 'evt_de_sur');
        $this->turnoEnLaBase($this->tenant, 'evt_de_sur');

        // Canario: Norte responde, y su turno perdió el evento de verdad.
        $this->turnoEnLaBase($otro, 'evt_de_norte', 'Ana');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($otro, 'evt_de_norte')?->status,
            'Canario: la corrida no canceló el turno de la PyME cuyo calendario sí respondía, '
            .'así que lo que este test afirma sobre el fallo no distingue nada.');

        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_de_sur')?->status,
            'Un 500 de Google canceló un turno real: la conciliación confundió «no pude '
            .'verificar» con «el evento no está» (RNF-03).');

        $this->assertSame(['evt_de_norte'], $this->turnosSinEventoReportados(),
            'Se reportó como turno sin evento uno cuyo calendario ni siquiera se pudo leer.');
    }

    /**
     * § 9 · Un turno **más lejos de lo que la conciliación mira** no se cancela.
     *
     * Es la misma confusión que el 500 de Google, con otro disfraz: la ventana es
     * `−2/+60 días` (`ConciliarAgendamientos::DIAS_HACIA_*`), y de un turno a 80
     * días la corrida **no tiene ninguna información** — no lo pidió. Si la
     * consulta a `bookings` no respetara la misma ventana que el listado a
     * Google, cada corrida cancelaría todos los turnos lejanos por no verlos: los
     * que la PyME agenda con más anticipación, que son los que más le importan.
     *
     * El doble ignora `timeMin`/`timeMax` a propósito, así que este test no puede
     * distinguirse por el lado de Google: lo que fija es que **el conjunto de
     * turnos a revisar tiene que estar acotado por la misma ventana**.
     *
     * ⚠️ Que el turno lejano quede intacto es criterio mío, coherente con el test
     * del 500: no haber mirado no es haber verificado que no está.
     */
    public function test_no_cancela_un_turno_que_cae_fuera_de_la_ventana_que_mira(): void
    {
        $this->fakeDeGoogle();

        // Más allá del horizonte de la conciliación, y sin evento en el doble.
        $this->turnoEnLaBase($this->tenant, 'evt_lejano', 'Ana', cuando: CarbonImmutable::now('UTC')
            ->addDays(ConciliarAgendamientos::DIAS_HACIA_ADELANTE + 20));

        // Canario: uno dentro de la ventana, que sí tiene que caer.
        $this->turnoEnLaBase($this->tenant, 'evt_cercano');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($this->tenant, 'evt_cercano')?->status,
            'Canario: la corrida no canceló el turno que sí estaba dentro de la ventana, así '
            .'que lo que este test afirma sobre el turno lejano no distingue nada.');

        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_lejano')?->status,
            'Se canceló un turno que la conciliación ni siquiera consultó: la ventana del '
            .'listado a Google y la de la consulta a bookings no son la misma.');

        $this->assertSame(['evt_cercano'], $this->turnosSinEventoReportados(),
            'Se reportó como turno sin evento uno que cae fuera de la ventana revisada.');
    }

    /**
     * § 9 · Un turno **que ya ocurrió** no se cancela nunca por conciliación.
     *
     * ⚠️ **Decisión del team-lead que Diego tiene que ratificar.** § 9 no lo dice:
     * habla de turnos vivos sin distinguir si ya pasaron. Va marcada, no
     * disfrazada de decidida.
     *
     * El fundamento son los dos daños que § 9 nombra, y ninguno aplica hacia
     * atrás. **«Ocupa la agenda»**: un horario que ya transcurrió no ocupa nada.
     * **«Cuenta para la tasa de ausentismo»**: acá es peor que inútil, es
     * destructivo — la tasa se calcula solo sobre los turnos marcados (§ 1) y un
     * turno cancelado no cuenta como ausente, así que cancelar hacia atrás
     * **saca turnos de la métrica en silencio**.
     *
     * Y el gesto no significa lo mismo en cada dirección: borrar un evento viejo
     * del calendario es higiene, la hace cualquiera. Borrar uno futuro es una
     * decisión sobre un turno. Solo la segunda es una señal que valga interpretar.
     *
     * El turno pasado está **dentro** de la ventana `−2/+60` a propósito: si
     * cayera afuera, este test lo pasaría el mismo código que respeta la ventana
     * y no probaría nada nuevo.
     */
    public function test_no_cancela_un_turno_que_ya_ocurrio(): void
    {
        $this->fakeDeGoogle();

        // Ayer, dentro de la ventana hacia atrás, y sin evento en el calendario.
        $this->turnoEnLaBase($this->tenant, 'evt_de_ayer', 'Ana',
            cuando: CarbonImmutable::now('UTC')->subHours(20));

        // Canario: el de mañana, que sí tiene que caer en la misma corrida.
        $this->turnoEnLaBase($this->tenant, 'evt_de_maniana');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($this->tenant, 'evt_de_maniana')?->status,
            'Canario: la corrida no canceló el turno futuro que perdió su evento, así que lo '
            .'que este test afirma sobre el turno pasado no distingue nada.');

        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_de_ayer')?->status,
            'Se canceló un turno que ya ocurrió: el dueño limpió su calendario viejo —higiene '
            .'normal— y con eso borró un turno de la métrica de ausentismo sin que nadie lo pida.');

        $this->assertSame(['evt_de_maniana'], $this->turnosSinEventoReportados(),
            'Se reportó como turno sin evento uno que ya ocurrió: el reporte se llena de '
            .'housekeeping del dueño y deja de servir para contar desalineados reales.');
    }

    /**
     * § 9 · El turno pasado **con asistencia marcada** conserva su asistencia.
     *
     * Es el caso que de verdad duele, y por eso va aparte del anterior. Alguien
     * registró que ese cliente vino: es un dato real, tomado por una persona, con
     * su auditoría de quién lo marcó y cuándo (T-036). Si la conciliación
     * cancelara el turno, ese `attended` deja de contar para la tasa —la métrica
     * mira los turnos marcados y el cancelado no es uno— y el número con el que
     * se vende el producto empeora solo, sin que nadie haya hecho nada mal.
     *
     * Se afirman las dos columnas: que el turno no se canceló **y** que la
     * asistencia sigue ahí. Son ortogonales (T-036) y se rompen por separado.
     */
    public function test_un_turno_pasado_con_asistencia_marcada_conserva_su_asistencia(): void
    {
        $this->fakeDeGoogle();

        $this->turnoEnLaBase($this->tenant, 'evt_atendido', 'Ana',
            cuando: CarbonImmutable::now('UTC')->subHours(20),
            asistencia: Booking::ASISTENCIA_ASISTIO);

        // Canario: uno futuro sin evento, que sí tiene que caer.
        $this->turnoEnLaBase($this->tenant, 'evt_de_maniana');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($this->tenant, 'evt_de_maniana')?->status,
            'Canario: la corrida no canceló el turno futuro que perdió su evento, así que lo '
            .'que este test afirma sobre el turno atendido no distingue nada.');

        $fila = $this->filaCruda($this->tenant, 'evt_atendido');

        $this->assertSame(Booking::ASISTENCIA_ASISTIO, $fila?->attendance,
            'La conciliación pisó una asistencia que registró una persona: T-036 guarda quién '
            .'la marcó y cuándo, justamente porque es un dato que nadie más puede reconstruir.');

        $this->assertSame(Booking::ESTADO_AGENDADO, $fila?->status,
            'Se canceló un turno al que el cliente efectivamente vino: sale de la tasa de '
            .'ausentismo y el número empeora solo, sin que nadie haya hecho nada mal.');
    }

    // ----------------------------- § 9 · el listado incompleto no autoriza a cancelar

    /**
     * § 9 · Con el listado **truncado**, el barrido inverso no cancela nada de
     * esa PyME.
     *
     * Para el barrido de huérfanos un listado incompleto cuesta un reporte
     * perdido. Para el inverso cuesta turnos reales: el set se arma con lo que
     * vino, y todo turno que no esté ahí se cancela. La PyME afectada pierde
     * turnos vivos **con la confirmación ya en el celular del cliente**, y cada
     * corrida de 15 minutos lo vuelve a hacer.
     *
     * Es la tercera cara de lo mismo que ya fijaron el test del 500 y el de la
     * ventana: **no haber mirado no es haber verificado que no está.**
     *
     * ## Expectativa vieja, actualizada al implementar la paginación
     *
     * Antes este test montaba **un `nextPageToken` en la respuesta** y eso
     * bastaba para dar el listado por truncado, porque `eventosPropios()` pedía
     * 250 eventos y se quedaba con lo que viniera. Ahora se sigue el token: un
     * `nextPageToken` significa «hay más, andá a buscarlo», no «me quedé corto».
     * El caso que hay que montar para que este test siga probando lo mismo es el
     * **patológico**: un calendario que no se termina de leer ni agotando el
     * tope de páginas. **El comportamiento afirmado no cambió** —listado
     * incompleto, no se cancela— cambió qué situación lo produce.
     *
     * El canario es temporal y no cruzado: el doble es patológico **solo en el
     * primer listado**, así que la segunda corrida —idéntica en todo lo demás—
     * sí tiene que cancelar. Sin eso, «no cancela» lo pasa cualquier cosa.
     */
    public function test_con_el_listado_truncado_no_cancela_los_turnos_de_esa_pyme(): void
    {
        $this->fakeDeGoogle(
            tokenPatologico: $this->tokenDe(self::PHONE_NUMBER_ID),
            listadosPatologicos: 1,
        );

        // El evento vive en alguna de las páginas que nunca se llegaron a leer:
        // para el doble, no está.
        $this->turnoEnLaBase($this->tenant, 'evt_en_la_pagina_dos');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_en_la_pagina_dos')?->status,
            'Se canceló un turno vivo porque su evento estaba en una página del calendario que '
            .'la conciliación nunca leyó: el cliente ya tiene la confirmación en el celular.');

        $this->assertSame([], $this->turnosSinEventoReportados(),
            'Se reportó como turno sin evento uno cuyo calendario se leyó a medias.');

        // Canario: la misma corrida, ya sin truncar, sí lo cancela.
        $this->conciliar();

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($this->tenant, 'evt_en_la_pagina_dos')?->status,
            'Canario: con el listado completo tampoco se canceló, así que lo que este test '
            .'afirma sobre el listado truncado no distingue nada.');
    }

    /**
     * § 9 · Que el barrido inverso se haya frenado **queda registrado**.
     *
     * Un freno que no se registra es indistinguible del silencio. La PyME con más
     * de 250 eventos en la ventana no es un caso raro y no se arregla sola: **su
     * dirección inversa no se está barriendo nunca**, corrida tras corrida, y
     * desde afuera se ve igual que una PyME que está perfecta. Alguien tiene que
     * poder enterarse y paginar el listado.
     *
     * ⚠️ El código es `CONCILIACION_INVERSA_OMITIDA`, elegido por mí y distinto
     * del `CONCILIACION_LISTADO_TRUNCADO` que ya existe. Ver la nota de la
     * constante: uno describe la lectura, el otro el servicio que no se prestó.
     *
     * ## Expectativa vieja, actualizada al implementar la paginación
     *
     * Antes alcanzaba con que el doble devolviera **un `nextPageToken`** para
     * que el listado se diera por truncado. Ahora el token se sigue, así que el
     * caso patológico se monta con un calendario que no se termina de leer ni
     * agotando el tope de páginas. **Lo afirmado no cambió**: cuando el listado
     * queda incompleto, el freno se registra.
     */
    public function test_registra_que_no_barrio_la_pyme_por_listado_incompleto(): void
    {
        $this->fakeDeGoogle(tokenPatologico: $this->tokenDe(self::PHONE_NUMBER_ID));

        $this->turnoEnLaBase($this->tenant, 'evt_en_la_pagina_dos');

        $this->conciliar();

        $this->assertCount(1, $this->inversaOmitida,
            'La conciliación se saltó el barrido inverso de una PyME sin dejar rastro: desde '
            .'afuera es idéntico a una PyME que está sana, y así se queda para siempre.');

        // `tenant_id` como string: es UUID, y un `(int)` devuelve 1 para todos.
        $this->assertSame($this->tenant->id, (string) ($this->inversaOmitida[0]['tenant_id'] ?? ''),
            'El registro no dice de qué PyME es: sin eso nadie sabe a cuál hay que ir a mirar.');
    }

    /**
     * § 9 · La PyME con el listado truncado **no frena a las demás**.
     *
     * Es el mismo patrón que ya tienen el calendario inaccesible y el 500: el
     * problema de una no puede dejar sin conciliar a todas. Si el freno se
     * implementara cortando la corrida —un `return` en `handle()` en vez de en la
     * rama del tenant—, una sola PyME grande dejaría al resto sin servicio y
     * nadie lo notaría, porque el síntoma es *no pasa nada*.
     *
     * ## Expectativa vieja, actualizada al implementar la paginación
     *
     * El montaje pasó de «el listado de esta cuenta vuelve con `nextPageToken`»
     * a «el calendario de esta cuenta no se termina de leer ni agotando el
     * tope», que es lo que ahora produce un listado truncado. **Lo afirmado no
     * cambió.** Y con la paginación el test dice algo más caro que antes: la
     * PyME patológica ya no gasta una llamada a Google sino todas las del tope,
     * y aun así la siguiente tiene que quedar barrida.
     */
    public function test_el_listado_truncado_de_una_pyme_no_frena_a_las_demas(): void
    {
        $this->fakeDeGoogle(tokenPatologico: $this->tokenDe(self::PHONE_NUMBER_ID));

        $otro = $this->crearTenant('Consultorio Norte', '1053554814514903');

        $this->turnoEnLaBase($this->tenant, 'evt_de_sur');
        $this->turnoEnLaBase($otro, 'evt_de_norte', 'Ana');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($otro, 'evt_de_norte')?->status,
            'La PyME con el listado truncado dejó sin barrer a la siguiente: el freno tiene que '
            .'ser de ese tenant, no de la corrida.');

        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_de_sur')?->status,
            'Se canceló el turno de la PyME cuyo calendario se leyó a medias.');

        $this->assertSame(['evt_de_norte'], $this->turnosSinEventoReportados());
    }

    // ------------------------ § 9 · un UPDATE que falla no se lleva puesta la corrida

    /**
     * § 9 · Si el `UPDATE` de un turno falla, **las demás PyMEs se concilian
     * igual**.
     *
     * `cancelarTurnosSinEvento()` hace `$turno->save()` sin `catch`. Un deadlock,
     * una base que se cae un segundo, y la excepción sube hasta `handle()` y
     * **aborta la corrida entera**: las PyMEs que faltaban no se concilian, la
     * línea final nunca se imprime y nadie se entera de que la conciliación no
     * terminó. El patrón contrario ya existe tres líneas más arriba, para el
     * listado que no se pudo leer.
     *
     * El fallo se simula con un `updating` de Eloquent que lanza, que es
     * exactamente lo que hace un `save()` que no puede escribir. Sur se procesa
     * primero —se le atrasa el `created_at`, porque el reloj está congelado y dos
     * tenants creados en el mismo instante no tienen orden garantizado— y Norte
     * es el canario que prueba que la corrida siguió.
     *
     * ⚠️ Este test **no afirma** que el fallo se registre ni cuál sería su código:
     * el encargo pide que no frene a las demás, y el registro sería una decisión
     * más que nadie tomó. Vale la pena decidirla aparte.
     */
    public function test_un_update_que_falla_no_deja_sin_conciliar_a_las_demas_pymes(): void
    {
        $this->fakeDeGoogle();

        $otro = $this->crearTenant('Consultorio Norte', '1053554814514903');

        // Orden determinístico: el reloj está congelado y `orderBy('created_at')`
        // no alcanza para desempatar dos tenants creados en el mismo instante.
        DB::table('tenants')->where('id', $this->tenant->id)
            ->update(['created_at' => CarbonImmutable::now('UTC')->subDay()]);

        $this->turnoEnLaBase($this->tenant, 'evt_que_no_se_puede_guardar');
        $this->turnoEnLaBase($otro, 'evt_de_norte', 'Ana');

        Booking::updating(function (Booking $turno) {
            if ($turno->external_event_id === 'evt_que_no_se_puede_guardar') {
                throw new \RuntimeException('Deadlock found when trying to get lock');
            }
        });

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($otro, 'evt_de_norte')?->status,
            'Un UPDATE que falló en la primera PyME abortó la corrida: las demás quedaron sin '
            .'conciliar y ni siquiera se imprimió el resumen, así que nadie se entera.');

        // Que el turno de Sur siga vivo prueba que el fallo simulado ocurrió de
        // verdad. Si un día el barrido pasara a un `update()` masivo —que no
        // dispara eventos de modelo—, esta afirmación se pone roja en vez de
        // dejar el test verde sin probar nada.
        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_que_no_se_puede_guardar')?->status,
            'El fallo simulado no ocurrió: este test no está probando lo que dice.');
    }

    // ------------------------------------------ el camino largo, de punta a punta

    /**
     * AC-22.1 · El huérfano que la compensación **no pudo** limpiar.
     *
     * Los tests de arriba montan el huérfano a mano. Este lo produce recorriendo
     * el flujo real: el cliente elige un horario, Google crea el evento, el
     * `INSERT` falla —`bookings` no existe— y el borrado compensatorio de
     * `Fallback::liberarEvento()` **también falla**, porque Google contesta 500.
     *
     * Es el mismo estado final que deja un worker muerto, con la diferencia de
     * que acá nadie lo declaró: el evento está en el calendario del doble porque
     * el código lo puso ahí y no lo pudo sacar. Hoy eso termina en una línea
     * `EVENTO_HUERFANO_PERSISTE` y nada más; nadie vuelve a mirar.
     *
     * Se afirma de paso AC-22.2: el cliente **no** recibió confirmación.
     */
    public function test_el_huerfano_que_la_compensacion_no_pudo_limpiar_lo_encuentra_la_conciliacion(): void
    {
        $this->fakeDeGoogle(elBorradoFalla: true);

        $idHorario = $this->llegarHastaLaLista();

        DB::statement('RENAME TABLE bookings TO bookings_bak');

        try {
            (new ProcessMessageJob($this->toca($idHorario)))->handle();
        } finally {
            DB::statement('RENAME TABLE bookings_bak TO bookings');
        }

        // Precondición del escenario: el evento quedó en el calendario y no hay
        // fila. Si esto no se cumple, lo que sigue no prueba lo que dice.
        $enElCalendario = $this->calendarios[$this->tokenDe(self::PHONE_NUMBER_ID)] ?? [];
        $this->assertCount(1, $enElCalendario,
            'El escenario no se montó: no quedó un evento huérfano en el calendario.');
        $this->assertSame(0, DB::table('bookings')->count());

        // AC-22.2 · Ningún mensaje de confirmación salió.
        foreach ($this->textosEnviados() as $texto) {
            $this->assertStringNotContainsString('quedó reservado', $texto,
                'Se confirmó un turno que nunca se persistió.');
        }

        $this->conciliar();

        $this->assertSame(array_keys($enElCalendario), $this->eventosReportados(),
            'La conciliación no encontró el evento que la compensación no pudo borrar: '
            .'el huérfano queda bloqueando el horario y nadie se entera.');
    }

    // ------------------------------------------------- la tarea está programada

    /**
     * § 7 · Un comando que nadie corre no reporta nada, y **cada cuánto corre es
     * cuánto tiempo un horario puede quedar ocupado sin turno real detrás**.
     *
     * La decisión de conciliar en vez de compensar tiene como contracara que el
     * huérfano vive hasta la corrida siguiente: con una hora, la PyME puede pasar
     * una hora entera con un horario bloqueado que nadie va a usar y que ningún
     * cliente puede reservar. Quince minutos es lo que Diego aceptó pagar.
     *
     * ⚠️ **Expectativa vieja actualizada, no regresión.** Este test afirmaba solo
     * que el comando estaba programado, con la nota «la cadencia no la fija
     * ningún documento». Ahora la fija: `decisiones-tomadas.md` § 7.
     */
    public function test_la_conciliacion_esta_programada_cada_quince_minutos(): void
    {
        $programadas = collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains((string) $evento->command, self::COMANDO));

        $this->assertCount(1, $programadas,
            'El comando '.self::COMANDO.' no está en el scheduler: los desalineados se '
            .'acumulan y nadie los mira.');

        $this->assertSame('*/15 * * * *', $programadas->first()->expression,
            'La conciliación no corre cada 15 minutos: un horario puede quedar ocupado sin '
            .'turno real detrás durante toda la ventana entre dos corridas (§ 7).');
    }

    /**
     * § 7 · Mover la cadencia tiene que ser **una línea**.
     *
     * Diego pidió explícitamente que la frecuencia no sea un número suelto
     * escondido en el scheduler: si el piloto muestra que 15 minutos es mucho —o
     * que es un gasto inútil de llamadas a Google—, cambiarla no puede exigir
     * buscar dónde estaba escrita. Este test es el que hace que la constante sea
     * de verdad la fuente: si alguien la mueve a 5 y deja el `everyFifteen`
     * abajo, se pone rojo.
     *
     * ⚠️ **El nombre y la ubicación de la constante los elijo yo**:
     * `ConciliarAgendamientos::MINUTOS_ENTRE_CORRIDAS`, junto a las otras dos
     * constantes de política del comando (`DIAS_HACIA_ATRAS`,
     * `DIAS_HACIA_ADELANTE`), que ya viven ahí y no en `routes/console.php`.
     */
    public function test_la_frecuencia_de_la_conciliacion_vive_en_una_constante_con_nombre(): void
    {
        $this->assertSame(15, ConciliarAgendamientos::MINUTOS_ENTRE_CORRIDAS,
            'La constante que fija cada cuánto concilia no vale 15 (§ 7).');

        $programada = collect(app(Schedule::class)->events())
            ->first(fn ($evento) => str_contains((string) $evento->command, self::COMANDO));

        $this->assertSame(
            '*/'.ConciliarAgendamientos::MINUTOS_ENTRE_CORRIDAS.' * * * *',
            $programada?->expression,
            'El scheduler no deriva la cadencia de la constante: cambiarla no alcanza para '
            .'cambiar cuándo corre, que es justo lo que la constante existe para evitar.');
    }

    // ------------------------------ § 9 · la dirección inversa: fila sin evento

    /**
     * § 9 · El dueño borró el turno **desde su propio Google Calendar**.
     *
     * Para él ese turno no existe: liberó el horario a mano, seguramente porque
     * el cliente lo llamó por teléfono. Pero en nuestra base sigue vivo, ocupa la
     * agenda —ningún otro cliente puede reservar ese hueco— y **entra en el
     * cálculo de la tasa de ausentismo**, que es el número con el que se vende el
     * producto. La misma corrida que caza huérfanos tiene que cazar esto.
     */
    public function test_cancela_el_turno_vivo_cuyo_evento_ya_no_esta_en_el_calendario(): void
    {
        $this->fakeDeGoogle();

        // La fila existe; el calendario del tenant está vacío: el dueño lo borró.
        $this->turnoEnLaBase($this->tenant, 'evt_borrado_a_mano');

        $this->conciliar();

        $fila = $this->filaCruda($this->tenant, 'evt_borrado_a_mano');

        $this->assertSame(Booking::ESTADO_CANCELADO, $fila?->status,
            'El turno cuyo evento el dueño borró sigue vivo en la base: bloquea un horario '
            .'que en el calendario ya está libre y ensucia la tasa de ausentismo.');

        $this->assertSame(['evt_borrado_a_mano'], $this->turnosSinEventoReportados(),
            'La conciliación canceló el turno sin dejar registro: nadie puede auditar por qué '
            .'un turno se cayó solo.');
    }

    /**
     * § 9 · Ese turno **no es un ausente**. `attendance` no se toca.
     *
     * `status` y `attendance` son ortogonales a propósito (T-036). Un cliente que
     * no vino y un turno que el dueño borró de su calendario son dos cosas
     * distintas: al segundo **nadie lo esperó**. Si la conciliación marcara
     * `no_show`, cada limpieza administrativa del dueño le subiría la tasa de
     * ausentismo, y esa tasa es literalmente el número con el que se le muestra
     * al cliente que el producto sirve.
     *
     * ⚠️ La afirmación sobre el `status` **no es decoración**: sin ella este test
     * pasa en vacío, porque un comando que no hace nada tampoco toca
     * `attendance`. Es la precondición que le da sentido al resto — «el turno
     * *cancelado por la conciliación*» tiene que estar efectivamente cancelado.
     */
    public function test_el_turno_cancelado_por_la_conciliacion_no_queda_marcado_como_ausente(): void
    {
        $this->fakeDeGoogle();

        $this->turnoEnLaBase($this->tenant, 'evt_borrado_a_mano');

        $this->conciliar();

        $fila = $this->filaCruda($this->tenant, 'evt_borrado_a_mano');

        $this->assertSame(Booking::ESTADO_CANCELADO, $fila?->status,
            'Precondición del test: el turno ni siquiera se canceló, así que lo que este test '
            .'afirma sobre la asistencia no probaría nada.');

        $this->assertNotSame(Booking::ASISTENCIA_AUSENTE, $fila?->attendance,
            'La conciliación marcó como ausente a un cliente que nadie esperó: la tasa de '
            .'ausentismo cuenta ahora las decisiones administrativas del propio dueño.');

        $this->assertSame(Booking::ASISTENCIA_PENDIENTE, $fila?->attendance,
            'La conciliación tocó la columna de asistencia, que no es asunto suyo: registrar '
            .'quién vino es de T-036 y tiene su propia auditoría de quién lo marcó.');
    }

    /**
     * § 9 · El turno cuyo evento **sí está** no se toca.
     *
     * Sin este test, un comando que cancele todos los turnos en cada corrida
     * pasaría los dos anteriores y borraría la agenda entera de la PyME cada
     * quince minutos.
     *
     * Los **dos** turnos conviven en la misma corrida a propósito: el que perdió
     * su evento es el canario. Sin él, este test lo pasa también un comando que
     * no hace absolutamente nada, y entonces no distingue «no toca lo sano» de
     * «no toca nada».
     */
    public function test_no_toca_el_turno_cuyo_evento_sigue_en_el_calendario(): void
    {
        $this->fakeDeGoogle();

        $this->eventoEnElCalendario($this->tenant, 'evt_vivo');
        $this->turnoEnLaBase($this->tenant, 'evt_vivo');

        // El canario: mismo tenant, misma corrida, sin evento en el calendario.
        $this->turnoEnLaBase($this->tenant, 'evt_sin_evento', 'Ana');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($this->tenant, 'evt_sin_evento')?->status,
            'Canario: la corrida no canceló el turno que sí perdió su evento, así que lo que '
            .'este test afirma sobre el turno sano no distingue nada.');

        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_vivo')?->status,
            'La conciliación canceló un turno que está bien en los dos lados: el cliente va a '
            .'llegar a un turno que el sistema dio de baja sin avisarle a nadie.');

        $this->assertSame(['evt_sin_evento'], $this->turnosSinEventoReportados(),
            'Reportó como turno sin evento uno cuyo evento está en el calendario.');
    }

    /**
     * § 9 · RNF-01 · La conciliación de una PyME **no puede cancelar el turno de
     * otra**.
     *
     * Es el modo de falla más caro de los dos que tiene la dirección inversa: una
     * consulta a `bookings` sin filtrar por tenant encuentra el turno de
     * *Peluquería Sur*, pregunta por su evento **contra el calendario de
     * Consultorio Norte** —donde obviamente no está—, y lo cancela. La PyME
     * pierde turnos reales por culpa de una vecina que ni conoce.
     *
     * El turno de Sur está sano: su evento existe en su propio calendario. Norte
     * tiene un turno que sí perdió su evento —el canario, que obliga a que la
     * corrida haya hecho su trabajo de verdad—. Después de conciliar, el de Norte
     * tiene que estar cancelado y el de Sur intacto.
     */
    public function test_la_conciliacion_de_un_tenant_no_cancela_el_turno_de_otro(): void
    {
        $this->fakeDeGoogle();

        $otro = $this->crearTenant('Consultorio Norte', '1053554814514903');

        $this->eventoEnElCalendario($this->tenant, 'evt_de_sur');
        $this->turnoEnLaBase($this->tenant, 'evt_de_sur');

        // Canario: el turno de Norte perdió su evento y tiene que caer.
        $this->turnoEnLaBase($otro, 'evt_de_norte', 'Ana');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($otro, 'evt_de_norte')?->status,
            'Canario: la corrida no canceló el turno de Norte que perdió su evento, así que lo '
            .'que este test afirma sobre el aislamiento no distingue nada.');

        $fila = $this->filaCruda($this->tenant, 'evt_de_sur');

        $this->assertSame(Booking::ESTADO_AGENDADO, $fila?->status,
            'La conciliación de otra PyME canceló un turno que no era suyo: buscó la fila sin '
            .'filtrar por tenant y preguntó por el evento en el calendario equivocado (RNF-01).');

        $this->assertSame(['evt_de_norte'], $this->turnosSinEventoReportados(),
            'Se reportó como turno sin evento uno que sí tiene su evento, en el calendario de '
            .'su propio dueño.');

        // El id se compara como string: `tenant_id` es UUID y un `(int)` sobre un
        // UUID devuelve 1 para todos, con lo que la afirmación pasaría siempre.
        $this->assertSame($this->tenant->id, (string) $fila?->tenant_id);
        $this->assertNotSame($otro->id, (string) $fila?->tenant_id);
    }

    /**
     * § 9 · Un turno **ya cancelado** no se vuelve a cancelar ni se reporta otra
     * vez.
     *
     * Un turno cancelado no tiene evento en el calendario **por definición**: la
     * cancelación lo borra. Si la conciliación no los excluyera, cada corrida
     * —cada quince minutos, para siempre— reportaría de nuevo todos los turnos
     * cancelados en la historia de la PyME. El log dejaría de servir para contar
     * cuántos desalineados hubo, que es lo único para lo que sirve hoy.
     *
     * El turno vivo que acompaña es el canario: sin él, un comando que no hace
     * nada pasa este test.
     */
    public function test_no_vuelve_a_reportar_un_turno_que_ya_estaba_cancelado(): void
    {
        $this->fakeDeGoogle();

        $this->turnoEnLaBase($this->tenant, 'evt_ya_cancelado', estado: Booking::ESTADO_CANCELADO);

        // Canario: uno vivo que sí tiene que caer en esta misma corrida.
        $this->turnoEnLaBase($this->tenant, 'evt_recien_borrado', 'Ana');

        $this->conciliar();

        $this->assertSame(['evt_recien_borrado'], $this->turnosSinEventoReportados(),
            'Un turno ya cancelado se reportó como desalineado: cada corrida va a repetir todos '
            .'los cancelados de la historia y el reporte deja de decir nada.');

        $fila = $this->filaCruda($this->tenant, 'evt_ya_cancelado');

        $this->assertSame(Booking::ESTADO_CANCELADO, $fila?->status);
        $this->assertSame(Booking::ASISTENCIA_PENDIENTE, $fila?->attendance,
            'La conciliación tocó la asistencia de un turno cancelado hace rato.');
    }

    // ------------------------------------------------- el flujo, para el e2e

    /** @return array<string,mixed> */
    private function texto(string $cuerpo): array
    {
        return $this->payload(['from' => '5493764278402', 'id' => 'wamid.'.uniqid(),
            'type' => 'text', 'text' => ['body' => $cuerpo]]);
    }

    /** @return array<string,mixed> */
    private function toca(string $id): array
    {
        return $this->payload(['from' => '5493764278402', 'id' => 'wamid.'.uniqid(),
            'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => 'x']]]);
    }

    /** @param  array<string,mixed>  $mensaje */
    private function payload(array $mensaje): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [$mensaje]],
        ]]]]];
    }

    /** @return array<int,array<string,mixed>> */
    private function aMeta(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com')) {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    private function textosEnviados(): array
    {
        $out = [];

        foreach ($this->aMeta() as $m) {
            if (($m['type'] ?? null) === 'text') {
                $out[] = $m['text']['body'] ?? '';
            }
        }

        return $out;
    }

    /** Recorre el flujo hasta que la lista de horarios está en pantalla. */
    private function llegarHastaLaLista(string $nombre = 'María'): string
    {
        (new ProcessMessageJob($this->texto('Hola')))->handle();

        $bienvenida = $this->aMeta()[0];
        $reservar = $bienvenida['interactive']['action']['buttons'][0]['reply']['id'];

        (new ProcessMessageJob($this->toca($reservar)))->handle();
        (new ProcessMessageJob($this->texto($nombre)))->handle();

        foreach ($this->aMeta() as $m) {
            if (($m['interactive']['type'] ?? null) === 'list') {
                return $m['interactive']['action']['sections'][0]['rows'][0]['id'];
            }
        }

        $this->fail('Nunca se ofreció la lista de horarios.');
    }
}
