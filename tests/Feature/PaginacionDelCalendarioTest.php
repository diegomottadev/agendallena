<?php

namespace Tests\Feature;

use App\Console\Commands\ConciliarAgendamientos;
use App\Models\Booking;
use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Google\CalendarioDeGoogle;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * La paginación de `CalendarioDeGoogle::eventosPropios()`.
 *
 * **No tiene ticket: es el único agujero funcional que hay hoy en producción.**
 * `eventosPropios()` pide `maxResults => 250` y se queda con lo que vino. Si
 * Google devuelve `nextPageToken`, marca el listado como truncado y la
 * conciliación —correctamente— saltea el barrido inverso de esa PyME
 * (`CONCILIACION_INVERSA_OMITIDA`).
 *
 * Eso contiene el daño pero no lo resuelve: **a esa PyME no se le barre nunca
 * hacia atrás**. El dueño que borra un turno desde su propio Google Calendar
 * sigue teniendo el turno vivo en la base, ocupando un horario que él ya dio por
 * libre y contando para la tasa de ausentismo, que es el número con el que se
 * vende el producto. Una PyME con más de 250 eventos nuestros en la ventana de
 * 62 días entra en ese estado **y no sale sola**.
 *
 * ## El comportamiento que fija este archivo
 *
 * `eventosPropios()` sigue el `nextPageToken` hasta traer el calendario
 * completo, con un **tope de páginas** con nombre. El caso normal deja de estar
 * truncado y el barrido inverso vuelve a correr; el caso patológico —más páginas
 * que el tope— conserva la red que hay hoy.
 *
 * ## Decisiones que tuve que tomar para poder escribir los tests
 *
 * ⚠️ **El nombre del tope lo elijo yo:** `CalendarioDeGoogle::MAX_PAGINAS`.
 * Ningún documento lo fija. El criterio pedía que fuera una constante con
 * nombre, no un `10` suelto adentro de un `while`, así que el test la nombra.
 *
 * ⚠️ **Qué pasa si falla la segunda página lo elijo yo: se devuelve lo leído y
 * el listado queda marcado como truncado**, no `null`. El fundamento está en
 * `test_un_fallo_en_la_segunda_pagina_deja_el_listado_truncado`.
 */
class PaginacionDelCalendarioTest extends TestCase
{
    use RefreshDatabase;

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
     * Las páginas que sirve el doble, en orden.
     *
     * `[índice de página => items]`. Cada página se entrega **solo** cuando la
     * petición trae el `pageToken` que devolvió la anterior: es lo que hace que
     * «trajo dos páginas» no se pueda confundir con «pidió dos veces la
     * primera».
     *
     * @var array<int,array<int,array<string,mixed>>>
     */
    private array $paginas = [];

    /**
     * El caso patológico: **la última página siempre dice que hay más**.
     *
     * Es el calendario que no termina nunca, que es lo que se ve desde acá
     * cuando una PyME tiene muchísimos más eventos que el tope. Sirve para
     * probar que el tope corta.
     */
    private bool $siempreHayUnaPaginaMas = false;

    /** Qué página contesta 500. `null` = ninguna. */
    private ?int $paginaQueFalla = null;

    /**
     * Todas las peticiones al listado, en orden.
     *
     * @var array<int,Request>
     */
    private array $peticiones = [];

    /** Lo reportado como huérfano, en orden. @var array<int,array<string,mixed>> */
    private array $reportado = [];

    /** Lo reportado como turno sin evento, en orden. @var array<int,array<string,mixed>> */
    private array $reportadoSinEvento = [];

    /** Las PyMEs cuyo barrido inverso se frenó. @var array<int,array<string,mixed>> */
    private array $inversaOmitida = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC'));
        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        $this->tenant = $this->crearTenant('Peluquería Sur', self::PHONE_NUMBER_ID);

        Log::listen(function ($mensaje) {
            $codigo = $mensaje->context['codigo'] ?? null;

            if ($codigo === 'CONCILIACION_DESALINEADO') {
                $this->reportado[] = $mensaje->context;
            }

            if ($codigo === 'CONCILIACION_TURNO_SIN_EVENTO') {
                $this->reportadoSinEvento[] = $mensaje->context;
            }

            if ($codigo === 'CONCILIACION_INVERSA_OMITIDA') {
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
            'account_identifier' => 'duenio+'.$phoneNumberId.'@peluqueria.com',
            'access_token' => 'ya29.'.$phoneNumberId,
            'refresh_token' => '1//r', 'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        return $tenant;
    }

    private function googleDe(Tenant $tenant): Integration
    {
        return Integration::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
            ->firstOrFail();
    }

    /**
     * Declara qué página devuelve qué eventos.
     *
     * @param  array<int,string>  $eventIds
     */
    private function pagina(int $indice, array $eventIds): void
    {
        $inicio = CarbonImmutable::now('UTC')->addHours(20);

        $this->paginas[$indice] = array_map(fn (string $id) => [
            'id' => $id,
            'status' => 'confirmed',
            'summary' => 'Corte de pelo · María',
            'start' => ['dateTime' => $inicio->toRfc3339String(), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $inicio->addMinutes(30)->toRfc3339String(), 'timeZone' => 'UTC'],
            'extendedProperties' => ['private' => [
                'origen' => CalendarioDeGoogle::MARCA_ORIGEN,
                'tenant_id' => $this->tenant->id,
            ]],
        ], $eventIds);
    }

    /** Una fila de `bookings` que reclama ese evento. */
    private function turnoEnLaBase(Tenant $tenant, string $eventId, string $nombre = 'María'): Booking
    {
        $inicio = CarbonImmutable::now('UTC')->addHours(20);

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
            'status' => Booking::ESTADO_AGENDADO,
            'attendance' => Booking::ASISTENCIA_PENDIENTE,
        ]));
    }

    /**
     * La fila, **leída cruda de la base** y sin ningún scope de por medio.
     *
     * El Global Scope de `BelongsToTenant` podría esconder justo la fila que el
     * test quiere mirar, y entonces una fuga entre tenants se vería como "no
     * pasó nada".
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
     * respuesta feliz. Ya pasó tres veces en T-026. Por eso el doble decide qué
     * contestar mirando el `pageToken` de **la petición**, y no se re-arma.
     *
     * El doble es exigente con el token a propósito: un `pageToken` que él no
     * emitió se contesta con `400`, igual que Google. Una implementación que
     * «pagine» volviendo a pedir la primera página no puede pasar por completa.
     */
    private function fakeDeGoogle(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events?*' => function (Request $request) {
                $this->peticiones[] = $request;

                $indice = $this->indiceDePagina($request);

                if ($indice === null) {
                    return Http::response(['error' => ['message' => 'Invalid pageToken']], 400);
                }

                if ($this->paginaQueFalla === $indice) {
                    return Http::response(['error' => ['message' => 'backendError']], 500);
                }

                $cuerpo = ['items' => $this->paginas[$indice] ?? []];

                if ($this->siempreHayUnaPaginaMas || isset($this->paginas[$indice + 1])) {
                    $cuerpo['nextPageToken'] = $this->tokenDePagina($indice + 1);
                }

                return Http::response($cuerpo);
            },

            'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt']),

            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response([], 404),

            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);
    }

    /** El token opaco con el que se pide la página `$indice`. */
    private function tokenDePagina(int $indice): string
    {
        return 'CiAKGjBpNDd2Nmp2Zml2cXRwYjBpOXA-pagina-'.$indice;
    }

    /**
     * Qué página está pidiendo esta petición. `null` = token que no emitimos.
     */
    private function indiceDePagina(Request $request): ?int
    {
        $token = $this->parametro($request, 'pageToken');

        if ($token === null) {
            return 0;
        }

        $prefijo = $this->tokenDePagina(0);
        $prefijo = substr($prefijo, 0, strlen($prefijo) - 1);

        $indice = str_starts_with($token, $prefijo) ? substr($token, strlen($prefijo)) : '';

        return ctype_digit($indice) && (int) $indice > 0 ? (int) $indice : null;
    }

    /**
     * Un parámetro de la query, parseado a mano.
     *
     * `parse_str()` colapsa las claves repetidas y se quedaría con la última,
     * que en `privateExtendedProperty` es la mitad del filtro.
     */
    private function parametro(Request $request, string $buscada): ?string
    {
        foreach ($this->paresDeLaQuery($request) as [$clave, $valor]) {
            if ($clave === $buscada) {
                return $valor;
            }
        }

        return null;
    }

    /** @return array<int,array{0:string,1:string}> */
    private function paresDeLaQuery(Request $request): array
    {
        $out = [];

        foreach (explode('&', (string) parse_url($request->url(), PHP_URL_QUERY)) as $par) {
            [$clave, $valor] = array_pad(explode('=', $par, 2), 2, '');
            $out[] = [urldecode($clave), urldecode($valor)];
        }

        return $out;
    }

    // ---------------------------------------------------------- utilidades

    /** @return array<int,array<string,mixed>>|null */
    private function listar(): ?array
    {
        return $this->listarCon(app(CalendarioDeGoogle::class));
    }

    /** @return array<int,array<string,mixed>>|null */
    private function listarCon(CalendarioDeGoogle $calendario): ?array
    {
        $ahora = CarbonImmutable::now('UTC');

        return $calendario->eventosPropios(
            $this->googleDe($this->tenant),
            $this->tenant,
            $ahora->subDays(ConciliarAgendamientos::DIAS_HACIA_ATRAS),
            $ahora->addDays(ConciliarAgendamientos::DIAS_HACIA_ADELANTE),
        );
    }

    private function conciliar(): void
    {
        // La tarea corre **fuera de un request**: no hereda ningún tenant. Si la
        // implementación se olvidara de establecerlo, el Global Scope de
        // `BelongsToTenant` lanza y el comando falla acá mismo.
        TenantContext::forget();

        $this->artisan('agendamientos:conciliar')->assertSuccessful();
    }

    /**
     * Los `id` que devolvió el listado, en orden.
     *
     * @param  array<int,array<string,mixed>>|null  $eventos
     * @return array<int,string>
     */
    private function idsDe(?array $eventos): array
    {
        return array_map(fn (array $e) => (string) ($e['id'] ?? ''), $eventos ?? []);
    }

    /** Los `external_event_id` reportados como desalineados. */
    private function eventosReportados(): array
    {
        return array_map(fn (array $c) => $c['external_event_id'] ?? null, $this->reportado);
    }

    /** Los `external_event_id` reportados como turnos sin evento. */
    private function turnosSinEventoReportados(): array
    {
        return array_map(fn (array $c) => $c['external_event_id'] ?? null, $this->reportadoSinEvento);
    }

    // ------------------------------------------------- trae el calendario entero

    /**
     * El listado devuelve **los eventos de las dos páginas**, en orden y sin
     * repetir.
     *
     * Es la razón de ser de todo el ciclo: hoy la PyME grande recibe la primera
     * página y el resto de su calendario no existe para el sistema. Con la
     * segunda página adentro, la conciliación vuelve a tener el calendario
     * completo, que es la única base sobre la que tiene derecho a cancelar.
     *
     * El orden importa porque es el del calendario: Google pagina un resultado
     * ya ordenado, y romperlo haría que cualquier lectura futura por posición
     * —un «el próximo turno»— mienta.
     */
    public function test_devuelve_los_eventos_de_las_dos_paginas(): void
    {
        $this->fakeDeGoogle();
        $this->pagina(0, ['evt_uno', 'evt_dos']);
        $this->pagina(1, ['evt_tres']);

        $eventos = $this->listar();

        $this->assertSame(['evt_uno', 'evt_dos', 'evt_tres'], $this->idsDe($eventos),
            'El listado no trajo el calendario completo: los eventos de la segunda página no '
            .'existen para el sistema, y sobre ese calendario incompleto la conciliación decide '
            .'qué turnos cancelar.');
    }

    /**
     * La segunda petición **manda el `pageToken` que devolvió la primera**.
     *
     * Sin esto, «trae dos páginas» podría estar pidiendo dos veces la misma:
     * Google contestaría lo mismo, la lista tendría el doble de eventos y nadie
     * se enteraría de que el resto del calendario sigue sin leerse. Es la
     * diferencia entre paginar y llamar dos veces.
     */
    public function test_la_segunda_peticion_lleva_el_token_que_devolvio_la_primera(): void
    {
        $this->fakeDeGoogle();
        $this->pagina(0, ['evt_uno']);
        $this->pagina(1, ['evt_dos']);

        $this->listar();

        $this->assertCount(2, $this->peticiones,
            'No se pidió la segunda página: con `nextPageToken` en la respuesta, quedarse en una '
            .'sola petición es justamente el agujero que este ciclo cierra.');

        $this->assertNull($this->parametro($this->peticiones[0], 'pageToken'),
            'La primera petición ya mandó un `pageToken`: el primer listado no tiene de dónde '
            .'sacarlo, así que ese token es inventado.');

        $this->assertSame($this->tokenDePagina(1), $this->parametro($this->peticiones[1], 'pageToken'),
            'La segunda petición no mandó el `pageToken` que devolvió la primera: está pidiendo '
            .'otra vez la misma página, no la que falta.');
    }

    /**
     * La segunda petición **conserva los filtros** de la primera.
     *
     * Google no recuerda la consulta: un `pageToken` sin `privateExtendedProperty`
     * devuelve la agenda entera del dueño —el dentista, el asado, los turnos que
     * cargó por teléfono—. Cada uno de esos entraría a la conciliación como un
     * evento nuestro sin fila. El aislamiento por `tenant_id` (RNF-01) viaja en
     * ese mismo filtro: dos PyMEs con la misma cuenta de Google se mezclarían.
     */
    public function test_la_segunda_peticion_conserva_los_filtros_de_la_primera(): void
    {
        $this->fakeDeGoogle();
        $this->pagina(0, ['evt_uno']);
        $this->pagina(1, ['evt_dos']);

        $this->listar();

        $this->assertCount(2, $this->peticiones,
            'No se pidió la segunda página, así que este test no puede mirar sus filtros.');

        $filtros = array_values(array_filter(
            $this->paresDeLaQuery($this->peticiones[1]),
            fn (array $par) => $par[0] === 'privateExtendedProperty',
        ));

        $this->assertContains(['privateExtendedProperty', 'origen='.CalendarioDeGoogle::MARCA_ORIGEN], $filtros,
            'La segunda página se pidió sin el filtro de origen: vuelve la agenda personal del '
            .'dueño y la conciliación la trata como turnos nuestros.');

        $this->assertContains(['privateExtendedProperty', 'tenant_id='.$this->tenant->id], $filtros,
            'La segunda página se pidió sin el filtro por tenant: si dos PyMEs conectaran la misma '
            .'cuenta de Google, los eventos de una entrarían en la conciliación de la otra (RNF-01).');
    }

    /**
     * Con dos páginas el listado **no queda marcado como truncado**.
     *
     * Es el flag que la conciliación mira para decidir si tiene derecho a
     * cancelar. Mientras diga `true`, a esa PyME no se le barre nunca hacia
     * atrás: el turno que el dueño borró de su calendario sigue vivo en la base,
     * ocupando agenda y contando para el ausentismo, corrida tras corrida.
     *
     * Un `nextPageToken` ya no significa «me quedé corto»: significa «hay más,
     * andá a buscarlo». Si se fue a buscar y el calendario terminó, el listado
     * está completo.
     */
    public function test_con_dos_paginas_el_listado_no_queda_truncado(): void
    {
        $this->fakeDeGoogle();
        $this->pagina(0, ['evt_uno']);
        $this->pagina(1, ['evt_dos']);

        $calendario = app(CalendarioDeGoogle::class);

        $this->listarCon($calendario);

        $this->assertFalse($calendario->ultimoListadoTruncado(),
            'El listado se leyó entero y sigue marcado como truncado: la conciliación va a seguir '
            .'salteando el barrido inverso de esta PyME para siempre.');
    }

    /**
     * **El caso que prueba que el agujero se cerró:** el turno cuyo evento está
     * en la segunda página **no se cancela**.
     *
     * Es el recorrido completo, con el comando real: si la paginación funciona
     * pero el flag quedó en `true`, o si el flag baja pero la segunda página no
     * entró en el set, este test lo ve. Los dos errores terminan igual — un
     * cliente con la confirmación en el celular y el turno cancelado en la base,
     * cada quince minutos.
     *
     * El canario es un turno cuyo evento **no está en ninguna página**: ese sí
     * tiene que cancelarse en la misma corrida. Sin él, «no canceló» lo pasa
     * cualquier implementación que no cancele nada nunca — que es exactamente la
     * protección que hay hoy.
     */
    public function test_el_turno_cuyo_evento_esta_en_la_segunda_pagina_no_se_cancela(): void
    {
        $this->fakeDeGoogle();
        $this->pagina(0, ['evt_pagina_uno']);
        $this->pagina(1, ['evt_pagina_dos']);

        $this->turnoEnLaBase($this->tenant, 'evt_pagina_uno');
        $this->turnoEnLaBase($this->tenant, 'evt_pagina_dos', 'Ana');
        $this->turnoEnLaBase($this->tenant, 'evt_que_el_duenio_borro', 'Sofía');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_pagina_dos')?->status,
            'Se canceló un turno vivo porque su evento estaba en la segunda página: el cliente ya '
            .'tiene la confirmación en el celular y nadie lo va a atender.');

        $this->assertSame(Booking::ESTADO_CANCELADO,
            $this->filaCruda($this->tenant, 'evt_que_el_duenio_borro')?->status,
            'Canario: el barrido inverso no corrió, así que este test no distingue nada — es el '
            .'mismo estado en el que la PyME grande está hoy.');

        $this->assertSame([], $this->inversaOmitida,
            'La conciliación se saltó el barrido inverso de una PyME cuyo calendario se leyó '
            .'entero: el freno tiene que quedar solo para el caso patológico.');

        $this->assertSame(['evt_que_el_duenio_borro'], $this->turnosSinEventoReportados(),
            'Lo que el barrido inverso reportó no coincide con lo que borró el dueño: o se llevó '
            .'puesto un turno de la segunda página, o no reportó el que sí había que cancelar.');
    }

    // ------------------------------------------------------------ el tope

    /**
     * El tope de páginas **vive en una constante con nombre**.
     *
     * Es una política, no plomería: es cuántas llamadas a Google estamos
     * dispuestos a gastar en una PyME antes de dejarla sin barrido inverso. Un
     * número suelto adentro de un `while` no se puede subir sin leer el
     * algoritmo, y el piloto lo va a querer mover.
     */
    public function test_el_tope_de_paginas_vive_en_una_constante_con_nombre(): void
    {
        $this->assertTrue(defined(CalendarioDeGoogle::class.'::MAX_PAGINAS'),
            'El tope de páginas no está en una constante con nombre: es la política de cuántas '
            .'llamadas gastamos por PyME, y nadie la puede mover sin leer el bucle.');

        $this->assertGreaterThanOrEqual(2, CalendarioDeGoogle::MAX_PAGINAS,
            'Un tope menor a 2 no pagina nada: es el comportamiento de hoy con otro nombre.');
    }

    /**
     * Superado el tope, con `nextPageToken` todavía presente, el listado
     * **vuelve a estar truncado**.
     *
     * Es la red que hay que conservar. El calendario que no termina nunca existe
     * —una PyME con miles de eventos nuestros en la ventana— y lo que quedó sin
     * leer sigue siendo indistinguible de lo que no existe. Ahí el barrido
     * inverso tiene que frenarse igual que hoy: **no haber mirado no es haber
     * verificado que no está.**
     *
     * También fija que el bucle **corta**: contra un Google que siempre devuelve
     * `nextPageToken`, un `while` sin tope no vuelve nunca y se lleva puesta la
     * corrida entera de todas las PyMEs.
     */
    public function test_superado_el_tope_el_listado_vuelve_a_estar_truncado(): void
    {
        $this->fakeDeGoogle();
        $this->siempreHayUnaPaginaMas = true;
        $this->pagina(0, ['evt_uno']);

        $calendario = app(CalendarioDeGoogle::class);

        $this->listarCon($calendario);

        $this->assertTrue($calendario->ultimoListadoTruncado(),
            'El calendario seguía teniendo páginas y el listado se dio por completo: el barrido '
            .'inverso va a cancelar turnos vivos que están en las páginas que no se leyeron.');

        $this->assertSame(CalendarioDeGoogle::MAX_PAGINAS, count($this->peticiones),
            'El tope no cortó donde dice la constante: de menos deja calendario sin leer sin '
            .'motivo, y de más —o sin tope— la corrida se cuelga contra un Google que siempre '
            .'contesta que hay una página más.');
    }

    /**
     * Superado el tope, la conciliación **saltea el barrido inverso**, como hoy.
     *
     * El caso patológico conserva la protección completa, no solo el flag: el
     * turno de esa PyME sigue vivo y queda registrado que a esa PyME no se le
     * está prestando el servicio.
     *
     * Se afirma además **cómo se llegó** al freno —agotando el tope, no viendo
     * un `nextPageToken`—: sin eso este test lo pasa igual el código de hoy, que
     * frena en la primera página, y entonces no distingue nada.
     */
    public function test_superado_el_tope_la_conciliacion_saltea_el_barrido_inverso(): void
    {
        $this->fakeDeGoogle();
        $this->siempreHayUnaPaginaMas = true;
        $this->pagina(0, ['evt_uno']);

        $this->turnoEnLaBase($this->tenant, 'evt_que_no_se_leyo');

        $this->conciliar();

        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_que_no_se_leyo')?->status,
            'Se canceló un turno vivo a partir de un calendario que se leyó a medias.');

        $this->assertCount(1, $this->inversaOmitida,
            'La conciliación se saltó el barrido inverso sin dejar rastro: desde afuera es '
            .'idéntico a una PyME sana, y así se queda para siempre.');

        $this->assertSame(CalendarioDeGoogle::MAX_PAGINAS, count($this->peticiones),
            'El freno no llegó por agotar el tope sino por ver un `nextPageToken` y rendirse en la '
            .'primera página: eso es el comportamiento de hoy, el que deja a la PyME grande sin '
            .'barrido inverso para siempre.');
    }

    // -------------------------------------- el fallo a mitad de la paginación

    /**
     * Si falla **la segunda página**, se devuelve lo leído y el listado queda
     * **truncado** — nunca media lista que parezca completa.
     *
     * ⚠️ **Elección mía, y el criterio admitía la otra:** devolver `null`.
     * Elijo esta por dos razones.
     *
     * La información que queda en la mano es **exactamente la misma** que en el
     * caso del tope: un prefijo del calendario, y la certeza de que es un
     * prefijo. Dos situaciones con la misma información tienen que tratarse
     * igual, o el llamador termina con dos reglas para el mismo hecho.
     *
     * Y el prefijo **sirve para una de las dos direcciones**: un evento leído
     * que ninguna fila reclama es un huérfano de verdad, y lo que falte leer no
     * lo puede desmentir. Reportarlo no es destructivo. Lo que sí lo es
     * —cancelar— queda frenado por el flag, que es donde vive el principio de
     * que **no haber leído todo no es haber verificado que algo no está**.
     *
     * Devolver `null` sería más simple —«cualquier HTTP que falla, `null`»— pero
     * tira al piso reportes reales a cambio de nada.
     */
    public function test_un_fallo_en_la_segunda_pagina_deja_el_listado_truncado(): void
    {
        $this->fakeDeGoogle();
        $this->pagina(0, ['evt_uno']);
        $this->pagina(1, ['evt_dos']);
        $this->paginaQueFalla = 1;

        $calendario = app(CalendarioDeGoogle::class);

        $eventos = $this->listarCon($calendario);

        $this->assertCount(2, $this->peticiones,
            'La segunda página ni se intentó: sin ese intento este test no habla del fallo a mitad '
            .'de la paginación, habla del código de hoy que se detiene en la primera.');

        $this->assertSame(['evt_uno'], $this->idsDe($eventos),
            'Un fallo a mitad de la paginación tiene que devolver lo que sí se leyó: la primera '
            .'página se leyó bien y sus huérfanos son huérfanos igual.');

        $this->assertTrue($calendario->ultimoListadoTruncado(),
            'Media lista se devolvió como si fuera el calendario completo: el barrido inverso va a '
            .'cancelar turnos vivos que estaban en la página que Google no llegó a contestar.');
    }

    /**
     * El fallo en la segunda página **no hace cancelar ningún turno**, y la
     * corrida igual reporta lo que sí leyó.
     *
     * Es la misma decisión mirada desde el comando: media lista no autoriza a
     * cancelar, pero sí a reportar. El huérfano de la primera página es el
     * canario —sin él, «no canceló nada» lo pasa una corrida que no hizo nada—.
     */
    public function test_un_fallo_en_la_segunda_pagina_no_cancela_turnos(): void
    {
        $this->fakeDeGoogle();
        $this->pagina(0, ['evt_huerfano_de_la_uno']);
        $this->pagina(1, ['evt_de_la_dos']);
        $this->paginaQueFalla = 1;

        $this->turnoEnLaBase($this->tenant, 'evt_de_la_dos');

        $this->conciliar();

        $this->assertCount(2, $this->peticiones,
            'La segunda página ni se intentó: sin ese intento la corrida no está tratando un fallo '
            .'a mitad de la paginación, está haciendo lo de hoy.');

        $this->assertSame(Booking::ESTADO_AGENDADO,
            $this->filaCruda($this->tenant, 'evt_de_la_dos')?->status,
            'Se canceló un turno vivo cuyo evento estaba en la página que Google no llegó a '
            .'contestar: no leerla no es haber verificado que el evento no está.');

        $this->assertSame(['evt_huerfano_de_la_uno'], $this->eventosReportados(),
            'Canario: la corrida no reportó el huérfano que sí se leyó, así que lo de arriba no '
            .'distingue nada — el listado se descartó entero.');
    }
}
