<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Conversacion\Pausa;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-047 · Panel: listado de conversaciones.
 *
 * ## Por qué importa
 *
 * El modelo de tarifa plana deja de cerrar apenas se pasa de un puñado de
 * cuentas: sin listado, cada consulta del dueño se atiende con soporte humano y
 * el margen se lo come el soporte. Y es acá donde el aislamiento del RNF-01 se
 * vuelve **visible**: un tenant viendo conversaciones de otro es el incidente
 * que termina el contrato.
 *
 * ## Absorbe la pantalla mínima de T-025
 *
 * El listado vive en la misma URL que ya usa T-025 (`/panel/conversaciones`),
 * que hoy trae un límite fijo de 50 y ningún dato de turno. Eso es **deuda
 * declarada** por el propio recorte de T-025, no una pantalla aparte: este
 * ticket la absorbe. Por eso los tests de pausa y de derivación siguen valiendo
 * sobre esta misma URL y no se tocan.
 *
 * ⚠️ **El cotizador está congelado** (decisión § 10 de `decisiones-tomadas.md`):
 * `bookings.quote_amount` es `null` en todo el sistema hoy. La columna tiene que
 * existir y comportarse bien con `null` — no hay nada que la llene todavía.
 */
class PanelListadoDeConversacionesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/panel/conversaciones';

    private const TZ = 'America/Argentina/Buenos_Aires';

    /**
     * Un instante que en Buenos Aires cae **el día anterior**.
     *
     * Es el dato que hace al test de fecha valer algo: si el panel imprimiera la
     * hora cruda de la base, mostraría el 25 y el dueño buscaría al cliente el
     * día equivocado (RNF-02).
     */
    private const INICIO_UTC = '2026-08-25 01:30:00';

    private const DIA_LOCAL = '24/08/2026';

    private const DIA_UTC = '25/08/2026';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', self::TZ));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ andamiaje

    private function tenant(string $slug = 'piloto'): Tenant
    {
        return Tenant::create([
            'name' => 'Peluquería '.$slug, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);
    }

    private function usuario(Tenant $tenant, Role $rol = Role::Owner, string $nombre = 'Vanina'): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $nombre,
            'email' => $rol->value.'@'.$tenant->slug.'.test',
            'password' => 'secreto123',
            'role' => $rol,
        ]);
    }

    /** @var array<string,Integration> Una sola por tenant: `(provider, account_identifier)` es único. */
    private array $integraciones = [];

    private function integracionGoogle(Tenant $tenant): Integration
    {
        return $this->integraciones[(string) $tenant->id] ??= Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'agenda@'.$tenant->slug.'.com.ar',
            'access_token' => 'ya29.token',
            'refresh_token' => '1//refresh',
            'status' => 'connected',
        ]);
    }

    private function conversacion(
        Tenant $tenant,
        string $telefono = '5493764278402',
        ?string $nombre = 'Cliente Uno',
        Estado $estado = Estado::Booked,
        ?string $ultimaInteraccion = null,
    ): Conversation {
        return TenantContext::runAs($tenant->id, fn () => Conversation::create([
            'user_phone' => $telefono,
            'user_name' => $nombre,
            'current_state' => $estado->value,
            'last_interaction_at' => CarbonImmutable::parse($ultimaInteraccion ?? '2026-08-22 12:00:00', 'UTC'),
        ]));
    }

    private function turno(
        Tenant $tenant,
        Conversation $conversacion,
        string $eventId,
        ?string $monto = null,
        string $inicioUtc = self::INICIO_UTC,
        string $estado = Booking::ESTADO_AGENDADO,
    ): Booking {
        $integracion = $this->integracionGoogle($tenant);

        return TenantContext::runAs($tenant->id, fn () => Booking::create([
            'conversation_id' => $conversacion->id,
            'integration_id' => $integracion->id,
            'external_event_id' => $eventId,
            'client_name' => $conversacion->user_name ?? 'Cliente',
            'client_phone' => $conversacion->user_phone,
            'service_name' => 'Corte y barba',
            'quote_amount' => $monto,
            'start_time' => CarbonImmutable::parse($inicioUtc, 'UTC'),
            'end_time' => CarbonImmutable::parse($inicioUtc, 'UTC')->addMinutes(30),
            'status' => $estado,
        ]));
    }

    /** Conversaciones en bloque, para los tests de volumen. */
    private function sembrar(Tenant $tenant, int $cantidad, string $prefijo = '54937642'): void
    {
        $base = CarbonImmutable::parse('2026-08-24 12:00:00', 'UTC');

        foreach (array_chunk(range(1, $cantidad), 200) as $lote) {
            DB::table('conversations')->insert(array_map(fn (int $n) => [
                'tenant_id' => $tenant->id,
                'user_phone' => $prefijo.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'user_name' => 'Cliente '.$n,
                'current_state' => Estado::Idle->value,
                'last_interaction_at' => $base->subMinutes($n)->format('Y-m-d H:i:s'),
                'created_at' => now(),
                'updated_at' => now(),
            ], $lote));
        }
    }

    /**
     * Los `href` a Google Calendar que la página ofrece.
     *
     * Se leen del HTML y no de los datos de la vista a propósito: el criterio
     * dice *"ofrece un enlace"*, y un dato en el controlador que la vista no
     * imprime no es un enlace.
     *
     * @return array<int,string>
     */
    private function enlacesAlCalendario(string $html): array
    {
        preg_match_all('/href="([^"]*)"/i', $html, $m);

        return array_values(array_filter(
            array_map(fn (string $u) => html_entity_decode($u, ENT_QUOTES), $m[1]),
            fn (string $u) => str_contains($u, 'calendar.google.com') || str_contains($u, 'google.com/calendar'),
        ));
    }

    /**
     * ¿Este enlace apunta a **ese** evento?
     *
     * ⚠️ Acepta las dos formas posibles a propósito: el id crudo en la URL, o el
     * `eid` de Google, que es `base64(id + " " + calendario)`. Nadie decidió
     * cuál —no guardamos el `htmlLink` que devuelve la API—, así que el test
     * afirma *que identifica al evento correcto*, no cómo se arma la URL.
     */
    private function identificaAlEvento(string $url, string $eventId): bool
    {
        $url = urldecode($url);

        if (str_contains($url, $eventId)) {
            return true;
        }

        foreach (preg_split('/[?&=\/#]/', $url) ?: [] as $trozo) {
            if (strlen($trozo) < 8) {
                continue;
            }

            $crudo = base64_decode(strtr($trozo, '-_', '+/'), false);

            if (is_string($crudo) && str_contains($crudo, $eventId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Qué teléfonos de los sembrados se ven en la página.
     *
     * @param  array<int,string>  $telefonos
     * @return array<int,string>
     */
    private function visibles(string $html, array $telefonos): array
    {
        return array_values(array_filter($telefonos, fn (string $t) => str_contains($html, $t)));
    }

    /** @return array<int,string> */
    private function telefonosSembrados(int $cantidad, string $prefijo = '54937642'): array
    {
        return array_map(
            fn (int $n) => $prefijo.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            range(1, $cantidad),
        );
    }

    /**
     * Las consultas que dispara una request, para contarlas.
     *
     * @return array<int,\Illuminate\Database\Events\QueryExecuted>
     */
    private function consultasDe(User $usuario, string $url = self::URL): array
    {
        $consultas = [];

        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = $consulta;
        });

        $this->actingAs($usuario)->get($url)->assertSuccessful();

        return $consultas;
    }

    // -------------------------------------------- AC-14.1 · qué se ve por fila

    /**
     * AC-14.1 · La fila dice en qué anda la conversación.
     *
     * Sin esto el panel es una lista de teléfonos y el dueño igual tiene que
     * abrir el WhatsApp — que es exactamente el trabajo que veníamos a sacarle.
     */
    public function test_el_listado_muestra_el_estado_de_cada_conversacion(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $c = $this->conversacion($tenant, nombre: 'Marisa Duarte');
        $this->turno($tenant, $c, 'evt7q9m1c2k3v4b5n6m7a8s9d', monto: '4500.00');

        $html = $this->actingAs($usuario)->get(self::URL)->assertOk()->getContent();

        $this->assertStringContainsString('Marisa Duarte', $html,
            'La fila no identifica al cliente.');

        // El estado, en castellano: el enum es de T-018, acá solo se muestra.
        $this->assertStringContainsString('Turno agendado', $html,
            'La fila no dice en qué estado está la conversación (AC-14.1).');
    }

    /**
     * AC-14.1 · Y **cuándo es el turno**, en la hora del negocio.
     *
     * El turno arranca a las 01:30 UTC, que en Buenos Aires es la noche
     * anterior: si el panel imprimiera la hora cruda de la base, el dueño
     * buscaría al cliente el día equivocado (RNF-02).
     */
    public function test_el_listado_muestra_la_fecha_del_turno_en_la_zona_del_negocio(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $c = $this->conversacion($tenant, nombre: 'Marisa Duarte');
        $this->turno($tenant, $c, 'evt7q9m1c2k3v4b5n6m7a8s9d');

        $html = $this->actingAs($usuario)->get(self::URL)->assertOk()->getContent();

        $this->assertStringContainsString(self::DIA_LOCAL, $html,
            'La fecha del turno no aparece en la fila (AC-14.1).');
        $this->assertStringNotContainsString(self::DIA_UTC, $html,
            'Se imprimió la fecha cruda de la base (UTC): el turno se lee un día corrido.');
    }

    /**
     * AC-14.1 · Y **cuánto se cotizó**.
     *
     * ⚠️ Hoy no hay nada que lo llene —el cotizador está congelado—, pero la
     * columna es del criterio y el listado tiene que mostrarla cuando el dato
     * existe. Se siembra a mano justamente porque el camino que lo escribiría
     * (T-041) está diferido.
     */
    public function test_el_listado_muestra_el_monto_cotizado_cuando_lo_hay(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $c = $this->conversacion($tenant, nombre: 'Marisa Duarte');
        $this->turno($tenant, $c, 'evt7q9m1c2k3v4b5n6m7a8s9d', monto: '4500.00');

        $html = $this->actingAs($usuario)->get(self::URL)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/4[.,\s]?500/', $html,
            'El monto cotizado no aparece en la fila (AC-14.1).');
    }

    /**
     * ⚠️ **El cotizador está congelado, así que hoy el monto es siempre `null`.**
     *
     * La columna tiene que aguantar eso sin romper y **sin inventar un cero**:
     * "cotizado en $0" es una afirmación distinta de "no se cotizó", y con el
     * cotizador apagado la segunda es la verdadera.
     */
    public function test_un_turno_sin_monto_cotizado_no_rompe_el_listado_ni_se_muestra_como_cero(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $sinCotizar = $this->conversacion($tenant, '5493764111111', nombre: 'Sin cotizar');
        $this->turno($tenant, $sinCotizar, 'evtsincotizar0001', monto: null);

        // Canario: sin una fila cotizada al lado, "no muestra cero" pasaría con
        // el listado entero roto o sin columna de monto.
        $conMonto = $this->conversacion($tenant, '5493764222222', nombre: 'Con monto');
        $this->turno($tenant, $conMonto, 'evtconmonto0001', monto: '4500.00');

        $html = $this->actingAs($usuario)->get(self::URL)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/4[.,\s]?500/', $html,
            'No hay columna de monto en el listado: el "sin cotizar" de abajo no prueba nada.');
        $this->assertStringContainsString('Sin cotizar', $html,
            'Un turno sin monto se llevó puesta la fila entera.');
        $this->assertDoesNotMatchRegularExpression('/\b0[.,]00\b/', $html,
            'Un turno sin cotizar se muestra como cotizado en cero: son dos afirmaciones distintas.');
    }

    /** Una conversación sin turno también se lista: es el lead que todavía no reservó. */
    public function test_una_conversacion_sin_turno_aparece_igual_en_el_listado(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $this->conversacion($tenant, '5493764111111', nombre: 'Todavía preguntando', estado: Estado::GatheringParams);

        $this->actingAs($usuario)->get(self::URL)
            ->assertOk()
            ->assertSee('5493764111111')
            ->assertSee('Pidiendo datos');
    }

    // ------------------------------------------- AC-14.3 · enlace al calendario

    /**
     * AC-14.3 · El enlace abre **el evento correcto**, no la agenda del día.
     *
     * Es la diferencia entre resolver el reclamo en dos minutos y ponerse a
     * buscar a mano en Google Calendar. Por eso hay dos turnos: con uno solo, un
     * enlace equivocado pasaría igual.
     */
    public function test_cada_turno_ofrece_el_enlace_a_su_propio_evento_de_google_calendar(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $unaConv = $this->conversacion($tenant, '5493764111111', nombre: 'Marisa');
        $otraConv = $this->conversacion($tenant, '5493764222222', nombre: 'Rubén');

        $uno = 'evtaaaa1111bbbb2222cccc';
        $otro = 'evtzzzz9999yyyy8888xxxx';

        $this->turno($tenant, $unaConv, $uno);
        $this->turno($tenant, $otraConv, $otro);

        $enlaces = $this->enlacesAlCalendario(
            $this->actingAs($usuario)->get(self::URL)->assertOk()->getContent()
        );

        $this->assertNotEmpty($enlaces,
            'El listado no ofrece ningún enlace a Google Calendar (AC-14.3).');

        foreach ([$uno, $otro] as $eventId) {
            $apunta = array_filter($enlaces, fn (string $u) => $this->identificaAlEvento($u, $eventId));

            $this->assertNotEmpty($apunta,
                "Ningún enlace del listado abre el evento {$eventId}. Enlaces encontrados: "
                .implode(' | ', $enlaces));
        }
    }

    /** Sin turno no hay evento, y un enlace roto es peor que ninguno. */
    public function test_una_conversacion_sin_turno_no_ofrece_enlace_al_calendario(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $conTurno = $this->conversacion($tenant, '5493764111111', nombre: 'Reservó');
        $this->turno($tenant, $conTurno, 'evtaaaa1111bbbb2222cccc');

        // Canario: si el enlace del turno no se dibuja, el conteo de abajo da 0
        // por el motivo equivocado.
        $this->conversacion($tenant, '5493764222222', nombre: 'Solo preguntó', estado: Estado::GatheringParams);

        $enlaces = $this->enlacesAlCalendario(
            $this->actingAs($usuario)->get(self::URL)->assertOk()->getContent()
        );

        $this->assertCount(1, $enlaces,
            'Hay un turno y una conversación sin turno, y el listado ofrece '.count($enlaces).' enlaces '
            .'al calendario: o falta el del turno, o la conversación sin turno ofrece uno roto. '
            .'Enlaces: '.implode(' | ', $enlaces));
    }

    // ----------------------------- AC-14.4 (parcial) · la pausa, en el listado

    /**
     * AC-14.4 · Una conversación que atiende una persona **tiene que verse
     * distinta**, con cuánto le queda de pausa.
     *
     * Es lo que evita que alguien mande al bot a pisar una conversación que un
     * compañero está atendiendo a mano. Las etiquetas son las que fijó T-025:
     * este ticket absorbe esa pantalla, no la reemplaza.
     */
    public function test_una_conversacion_pausada_aparece_marcada_con_el_tiempo_restante(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant, nombre: 'Vanina Gómez');
        $c = $this->conversacion($tenant, '5493764111111', nombre: 'La pausada');
        $this->conversacion($tenant, '5493764222222', nombre: 'La que sigue con el bot');

        TenantContext::runAs($tenant->id, fn () => Pausa::activar($c, Pausa::ORIGEN_MANUAL, (string) $usuario->id));

        $this->travel(20)->minutes();

        $this->actingAs($usuario)->get(self::URL)
            ->assertOk()
            ->assertSee('Pausado desde el panel')
            ->assertSee('Vuelve solo en '.(Pausa::MINUTOS - 20).' min')
            ->assertSee('Lo pausó Vanina Gómez', false);
    }

    // ------------------------------------------------- RNF-01 · aislamiento

    /**
     * RNF-01 · AC-14.1 · **Únicamente** las del propio tenant.
     *
     * El canario no es opcional: sin una conversación propia que sí tenga que
     * aparecer, este test pasaría con el listado vacío o con la ruta rota.
     *
     * ⚠️ `tenant_id` es UUID. Nunca comparar con `(int)`: devuelve `1` para
     * todos y el aislamiento pasaría siempre, incluso con la fuga presente.
     */
    public function test_el_listado_no_muestra_conversaciones_de_otro_tenant(): void
    {
        $mio = $this->tenant('mio');
        $ajeno = $this->tenant('ajeno');

        $this->assertNotSame((string) $mio->id, (string) $ajeno->id);

        $propia = $this->conversacion($mio, '5493764111111', nombre: 'Cliente propio');
        $this->turno($mio, $propia, 'evtpropio11112222');

        $deOtro = $this->conversacion($ajeno, '5493764999999', nombre: 'Cliente ajeno');
        $this->turno($ajeno, $deOtro, 'evtajeno99998888');

        $html = $this->actingAs($this->usuario($mio))->get(self::URL)->assertOk()->getContent();

        // Canario primero: si esto falla, el resto del test no prueba nada.
        $this->assertStringContainsString('5493764111111', $html,
            'El listado no muestra ni las conversaciones propias: el aislamiento de abajo no prueba nada.');

        $this->assertStringNotContainsString('5493764999999', $html,
            'Se filtró el teléfono de un cliente de otra PyME (RNF-01).');
        $this->assertStringNotContainsString('Cliente ajeno', $html,
            'Se filtró el nombre de un cliente de otra PyME (RNF-01).');
        $this->assertStringNotContainsString('evtajeno99998888', $html,
            'Se filtró el evento de calendario de otra PyME (RNF-01).');
    }

    // ------------------------------------------------------------ el N+1

    /**
     * *"El listado no ejecuta consultas N+1: los datos de turno y cotización
     * vienen resueltos."*
     *
     * Es medible, no una opinión: se cuentan las consultas de una request con
     * **una** conversación contra las de una con **veinte**. Si el turno se
     * resuelve fila por fila, la segunda dispara veinte consultas más y el panel
     * se cae solo cuando el piloto empieza a tener volumen.
     */
    public function test_el_listado_no_ejecuta_consultas_n_mas_1(): void
    {
        $flaco = $this->tenant('flaco');
        $gordo = $this->tenant('gordo');
        $usuarioFlaco = $this->usuario($flaco);
        $usuarioGordo = $this->usuario($gordo);

        $c = $this->conversacion($flaco, '5493764100001', nombre: 'Único');
        $this->turno($flaco, $c, 'evtflaco0001');

        foreach (range(1, 20) as $n) {
            $conv = $this->conversacion($gordo, '549376420'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), nombre: 'Cliente '.$n);
            $this->turno($gordo, $conv, 'evtgordo'.$n);
        }

        $una = $this->consultasDe($usuarioFlaco);
        $veinte = $this->consultasDe($usuarioGordo);

        $sql = implode("\n", array_map(fn ($q) => $q->sql, $veinte));

        $this->assertLessThanOrEqual(count($una) + 1, count($veinte),
            'El listado consulta de a una fila: '.count($una).' consultas con 1 conversación y '
            .count($veinte)." con 20. Las de la segunda:\n".$sql);

        // La afirmación fina del criterio: el turno se resuelve de una.
        $deTurnos = array_filter($veinte, fn ($q) => str_contains(strtolower($q->sql), 'from `bookings`'));

        $this->assertLessThanOrEqual(2, count($deTurnos),
            'Se consultó `bookings` '.count($deTurnos).' veces para 20 conversaciones: '
            .'los datos del turno no vienen resueltos (N+1).');
    }

    // -------------------------------------------------------- la paginación

    /**
     * *"La paginación sostiene un volumen de piloto sin degradarse."*
     *
     * Traer todo y paginar en la vista es exactamente lo que este criterio
     * prohíbe: la página tiene que traer un puñado de filas aunque haya
     * trescientas conversaciones.
     */
    public function test_la_primera_pagina_no_trae_todas_las_conversaciones(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $this->sembrar($tenant, 300);

        $telefonos = $this->telefonosSembrados(300);

        $html = $this->actingAs($usuario)->get(self::URL)->assertOk()->getContent();

        $visibles = $this->visibles($html, $telefonos);

        $this->assertNotEmpty($visibles,
            'La primera página está vacía con 300 conversaciones cargadas.');
        $this->assertLessThan(100, count($visibles),
            'La primera página trajo '.count($visibles).' conversaciones de 300: no hay paginado, '
            .'se carga todo y se recorta en la vista.');
    }

    /**
     * Paginar de verdad: la segunda página trae **otras**, sin repetir ni
     * saltear.
     *
     * Un orden inestable haría que el dueño vea dos veces al mismo cliente y
     * nunca a otro — el modo de falla clásico de paginar sin orden determinista.
     */
    public function test_la_segunda_pagina_trae_las_que_siguen_sin_repetir(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $this->sembrar($tenant, 60);

        $telefonos = $this->telefonosSembrados(60);

        $primera = $this->visibles(
            $this->actingAs($usuario)->get(self::URL)->assertOk()->getContent(), $telefonos
        );
        $segunda = $this->visibles(
            $this->actingAs($usuario)->get(self::URL.'?page=2')->assertOk()->getContent(), $telefonos
        );

        $this->assertNotEmpty($segunda,
            'La segunda página vino vacía con 60 conversaciones: no hay paginado.');
        $this->assertEmpty(array_intersect($primera, $segunda),
            'La página 2 repite conversaciones de la 1: o `?page` no pagina nada, o el orden del '
            .'paginado no es estable y el dueño ve dos veces al mismo cliente y nunca a otro.');
    }

    /**
     * Y **ninguna se pierde por el camino**: paginando se llega a todas.
     *
     * Es el modo de falla de la pantalla actual, que trae un límite fijo de 50:
     * con 300 conversaciones, 250 no existen para el dueño y no hay forma de
     * llegar a ellas. Eso es exactamente "degradarse con el volumen del piloto".
     */
    public function test_paginando_se_llega_a_todas_las_conversaciones(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $this->sembrar($tenant, 300);

        $telefonos = $this->telefonosSembrados(300);
        $alcanzadas = [];

        for ($pagina = 1; $pagina <= 40; $pagina++) {
            $enEstaPagina = $this->visibles(
                $this->actingAs($usuario)->get(self::URL.'?page='.$pagina)->assertOk()->getContent(),
                $telefonos,
            );

            $nuevas = array_diff($enEstaPagina, $alcanzadas);

            if ($nuevas === []) {
                break;
            }

            $alcanzadas = array_merge($alcanzadas, $nuevas);
        }

        $this->assertCount(300, $alcanzadas,
            'Paginando se llega a '.count($alcanzadas).' de 300 conversaciones: el resto es invisible '
            .'para el dueño, que es como falla hoy el límite fijo de la pantalla mínima de T-025.');
    }

    /** Con más volumen, la request no se pone más cara: el costo lo fija la página. */
    public function test_el_volumen_no_agrega_consultas(): void
    {
        $chico = $this->tenant('chico');
        $grande = $this->tenant('grande');
        $this->sembrar($chico, 5, '54937641');
        $this->sembrar($grande, 300, '54937643');

        $conPocas = $this->consultasDe($this->usuario($chico));
        $conMuchas = $this->consultasDe($this->usuario($grande));

        $this->assertLessThanOrEqual(count($conPocas) + 1, count($conMuchas),
            'Con 300 conversaciones la página hace '.count($conMuchas).' consultas contra '
            .count($conPocas).' con 5: el costo crece con el volumen del piloto.');
    }

    // ------------------------------------------------------- autorización

    /** Atender es de los tres roles: el staff también necesita ver el listado. */
    public function test_un_staff_ve_el_listado(): void
    {
        $tenant = $this->tenant();
        $this->conversacion($tenant, '5493764111111', nombre: 'Marisa');

        $this->actingAs($this->usuario($tenant, Role::Staff))->get(self::URL)
            ->assertOk()
            ->assertSee('5493764111111');
    }

    /** Sin sesión no hay listado: son conversaciones de clientes de terceros. */
    public function test_sin_login_no_hay_listado(): void
    {
        $this->get(self::URL)->assertRedirect('/login');
    }
}
