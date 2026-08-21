<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Integration;
use App\Models\ReconciliationFinding;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * § 8 y § 9 · **Turnos en duda**: la tabla de hallazgos y la pantalla que los
 * resuelve. Decisión del 2026-08-21.
 *
 * ## Qué cambió y por qué
 *
 * Hasta hoy, la conciliación que encontraba un turno vivo cuyo evento el dueño
 * había borrado de su Google Calendar **lo cancelaba**. Diego lo cambió: cancelar
 * en silencio deja al cliente sin enterarse —tiene la confirmación en el celular
 * y llega a la puerta—, y avisarle automáticamente choca con la ventana de 24 h
 * de Meta, que obliga a una plantilla aprobada que no existe.
 *
 * Ahora el turno **queda en duda** y aparece en el panel. Una persona lo resuelve:
 * lo **mantiene** o lo **cancela**. Si hay que hablar con el cliente lo hace desde
 * el número de atención humana (`business_settings.human_phone`), que es un
 * WhatsApp común y no la Cloud API: sin plantilla, sin ventana de 24 h y sin
 * costo por envío. **El cliente no recibe nada automático**, y por eso en este
 * archivo no hay un solo test que afirme un envío.
 *
 * La detección —qué turnos generan hallazgo y cuáles no— vive en
 * `ConciliacionDeAgendamientoTest`. Acá vive lo que se hace con el hallazgo.
 *
 * ## Por qué una tabla aparte y no `bookings.status`
 *
 * Mezclar *«qué pasó con la reserva»* con *«detectamos una inconsistencia»* es el
 * error que T-036 corrigió al sacar la asistencia de `status`. Son dos ejes: un
 * turno puede estar `confirmed` **y** en duda a la vez, y esa es justamente la
 * combinación que la pantalla existe para resolver.
 *
 * La tabla cierra además la decisión § 8 —ver los desalineados en el panel—, que
 * hoy es imposible porque la conciliación **no persiste nada**: solo emite al log.
 *
 * ## Contrato que estos tests fijan
 *
 * ⚠️ Ningún documento nombra la tabla, las columnas ni la URL. **Las elijo acá** y
 * quedan escritas:
 *
 * | Dónde | Qué |
 * | :-- | :-- |
 * | `reconciliation_findings` | La tabla. Una fila por desalineado detectado |
 * | `tenant_id` (UUID), `booking_id`, `type` | De quién es, qué turno, de qué tipo |
 * | `detected_at` | Cuándo lo vio la corrida |
 * | `resolved_at`, `resolved_by`, `resolution` | Cuándo, **quién** y qué decidió |
 * | `GET /panel/turnos-en-duda` | Variable de vista `hallazgos` |
 * | `POST /panel/turnos-en-duda/{hallazgo}/resolver` | Campo `resolucion`: `mantener` \| `cancelar` |
 *
 * `resolved_by` apunta a `users`, igual que `attendance_marked_by` en T-036: es
 * el mismo problema —una decisión humana sobre un turno— y merece la misma
 * auditoría.
 *
 * ## El candado va en el esquema, no en un `SELECT` previo
 *
 * La conciliación corre cada 15 minutos y el desalineado no se arregla solo: sin
 * candado, la misma inconsistencia deja **96 filas por día**. Una comprobación en
 * PHP —*"fijate si ya hay un hallazgo de este turno"*— deja abierta la ventana
 * entre el `SELECT` y el `INSERT`: dos workers que miran a la vez pasan los dos.
 * Es la lección de `live_event_id` y de `lead_spreadsheets`, y el motor es el
 * único que la puede garantizar.
 *
 * ⚠️ **El candado acá es el opuesto al de `live_event_id`, a propósito.** Allá la
 * fila cancelada **suelta** el candado —dos `NULL` no chocan en un único de
 * MySQL— para que el mismo horario se pueda volver a reservar. Acá la fila
 * resuelta **lo sigue reteniendo**: `unique(tenant_id, type, booking_id)` sin
 * ninguna condición sobre `resolved_at`. Ver la nota entera en
 * `test_un_hallazgo_resuelto_no_vuelve_a_aparecer_aunque_el_desalineado_siga`.
 *
 * ## Ambigüedades del encargo que tuve que resolver para escribir el test
 *
 * ⚠️ **Qué rol puede resolver no está definido.** Los tests usan `owner`, que
 * puede todo. **No afirmo nada sobre `staff` ni sobre `admin`.** Por coherencia
 * con `asistencia` y `recordatorios-fallidos` —quien está en el mostrador es
 * quien sabe si el turno va— la ruta debería ir con `rol:atender`, pero eso es
 * una decisión de producto y no la tomo yo.
 *
 * ⚠️ **Qué pasa si el desalineado se «des-desalinea»** —el dueño vuelve a crear
 * el evento en Google con el mismo `id` después de que alguien resolvió el
 * hallazgo, y después lo borra otra vez— **no está decidido y no lo afirmo**. Con
 * el candado tal como está, ese segundo borrado **no** genera hallazgo nuevo. Es
 * el precio de que un hallazgo resuelto no vuelva, y me parece el lado correcto
 * del error, pero es una decisión que hay que tomar.
 *
 * ⚠️ **Mantener no recrea el evento en Google**, y eso lo fija el encargo
 * explícitamente. El turno queda vivo en la base y ausente del calendario del
 * dueño: la inconsistencia sigue, resuelta por decisión y no por reparación.
 *
 * ⚠️ **No afirmo si `detected_at` se refresca** cuando la corrida vuelve a ver el
 * mismo desalineado. Se afirma que no aparece una fila nueva; si el implementador
 * refresca el instante o no es indiferente para lo que la pantalla necesita.
 */
class TurnosEnDudaTest extends TestCase
{
    use RefreshDatabase;

    /** ⚠️ La URL la elijo yo: ningún documento la nombra. */
    private const PANEL = '/panel/turnos-en-duda';

    private const COMANDO = 'agendamientos:conciliar';

    /**
     * **UTC−5 todo el año, sin horario de verano.**
     *
     * No es Buenos Aires a propósito: la suite corre en UTC, y BA comparte offset
     * con Santiago parte del año — eso ya hizo pasar por casualidad un test de
     * husos en T-031. Bogotá no coincide con UTC **ningún** día del año, así que
     * una hora mostrada sin convertir salta siempre, y salta por 5 horas exactas.
     */
    private const TZ = 'America/Bogota';

    /** El «ahora» de todos los tests, en UTC. En Bogotá son las 09:00. */
    private const AHORA = '2026-08-22 14:00:00';

    /** Mañana a las 15:00 UTC, que en Bogotá son **las 10:00**. */
    private const TURNO = '2026-08-23 15:00:00';

    private const PHONE_NUMBER_ID = '1053554814514902';

    /** El calendario del doble, por cuenta de Google. @var array<string,array<string,mixed>> */
    private array $calendarios = [];

    /** Los intentos de acceso cruzado que registró el panel. @var array<int,array<string,mixed>> */
    private array $cruzados = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC'));

        Log::listen(function ($mensaje) {
            if (($mensaje->context['codigo'] ?? null) === 'AUTZ_TENANT_CRUZADO') {
                $this->cruzados[] = $mensaje->context;
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

    private function tenant(string $slug = 'sur', string $phoneNumberId = self::PHONE_NUMBER_ID): Tenant
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
            'settings' => ['verify_token' => 'tok'],
            'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio+'.$phoneNumberId.'@peluqueria.com',
            'access_token' => 'ya29.'.$phoneNumberId,
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

    /**
     * Un turno vivo **sin evento en el calendario del doble**: el desalineado.
     *
     * No hace falta borrar nada: lo que define el caso no es cómo se llegó, sino
     * qué quedó — una fila viva que ningún evento respalda.
     */
    private function turnoSinEvento(Tenant $tenant, string $cliente = 'Ana', string $eventId = 'evt_borrado'): Booking
    {
        $inicio = CarbonImmutable::parse(self::TURNO, 'UTC');

        return TenantContext::runAs($tenant->id, function () use ($tenant, $inicio, $cliente, $eventId) {
            $google = Integration::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->firstOrFail();

            return Booking::create([
                'tenant_id' => $tenant->id,
                'conversation_id' => null,
                'integration_id' => $google->id,
                'external_event_id' => $eventId,
                'client_name' => $cliente,
                'client_phone' => '573001112233',
                'service_name' => 'Corte de pelo',
                'start_time' => $inicio,
                'end_time' => $inicio->addMinutes(30),
                'status' => Booking::ESTADO_AGENDADO,
                'attendance' => Booking::ASISTENCIA_PENDIENTE,
            ]);
        });
    }

    // ------------------------------------------------------------- el doble

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: un segundo `fake()` no reemplaza al
     * primero, el doble del error nunca se usa y el test pasa contra la respuesta
     * feliz. Ya pasó tres veces en T-026.
     *
     * El calendario del doble arranca **vacío**, que es el escenario entero de
     * este archivo: los eventos ya no están.
     */
    private function fakeDeGoogle(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events?*' => function (Request $request) {
                $token = trim(str_replace('Bearer', '', $request->header('Authorization')[0] ?? ''));

                return Http::response(['items' => array_values($this->calendarios[$token] ?? [])]);
            },

            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);
    }

    // ---------------------------------------------------------- utilidades

    /** La corrida, **fuera de la sesión de todo tenant**, como corre de verdad. */
    private function conciliar(): void
    {
        TenantContext::forget();

        $this->artisan(self::COMANDO)->assertSuccessful();
    }

    /**
     * Los hallazgos crudos de ese tenant, **sin ningún scope de por medio**.
     *
     * `DB::table()` a propósito: un Global Scope podría esconder justo la fila que
     * el test quiere mirar, y entonces una fuga entre tenants se leería como "no
     * pasó nada".
     *
     * @return array<int,object>
     */
    private function hallazgosCrudos(Tenant $tenant): array
    {
        return DB::table('reconciliation_findings')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function hallazgoDe(Booking $turno): ?object
    {
        return DB::table('reconciliation_findings')
            ->where('booking_id', $turno->id)
            ->first();
    }

    /** @return array<int,array<string,mixed>> */
    private function hallazgosDeLaVista(TestResponse $respuesta): array
    {
        $respuesta->assertViewHas('hallazgos');

        $lista = $respuesta->viewData('hallazgos');
        $lista = is_object($lista) && method_exists($lista, 'all') ? $lista->all() : (array) $lista;

        return array_values(array_map(fn ($h) => (array) $h, $lista));
    }

    private function resolver(User $usuario, int|string $hallazgoId, string $resolucion): TestResponse
    {
        return $this->actingAs($usuario)
            ->post(self::PANEL."/{$hallazgoId}/resolver", ['resolucion' => $resolucion]);
    }

    // --------------------------------------------- el candado anti-duplicados

    /**
     * **Dos corridas seguidas dejan un solo hallazgo pendiente.**
     *
     * Por qué importa: la conciliación corre **cada 15 minutos** y el desalineado
     * no se arregla solo — el evento no vuelve al calendario. Sin candado, un
     * único turno en duda deja 96 filas por día y 672 por semana, todas idénticas.
     * La pantalla que existe para que el dueño resuelva se vuelve la pantalla que
     * el dueño cierra, y con ella se pierde el único camino que tiene el cliente a
     * enterarse de que su turno está en veremos.
     *
     * Es la lección del candado de `notification_logs`, en el mismo lugar.
     */
    public function test_dos_corridas_sobre_el_mismo_desalineado_dejan_un_solo_hallazgo(): void
    {
        $this->fakeDeGoogle();

        $tenant = $this->tenant();
        $this->turnoSinEvento($tenant);

        $this->conciliar();
        $this->conciliar();

        $hallazgos = $this->hallazgosCrudos($tenant);

        $this->assertCount(1, $hallazgos,
            'Cada corrida dejó su propio hallazgo del mismo turno: en un día son 96 filas '
            .'idénticas y la pantalla deja de servir para nada.');

        $this->assertNull($hallazgos[0]->resolved_at,
            'El único hallazgo que quedó ya está resuelto: nadie lo va a ver en la pantalla.');
    }

    /**
     * **El candado está en el esquema, no en un `SELECT` previo.**
     *
     * Por qué importa: una comprobación en PHP —*"fijate si ya hay un hallazgo de
     * este turno"*— deja abierta la ventana entre el `SELECT` y el `INSERT`. Dos
     * workers que concilian a la vez la pasan los dos y el duplicado entra igual.
     * Es exactamente lo que este proyecto ya aprendió con `live_event_id` y con
     * `lead_spreadsheets`: **el motor es el único que lo puede garantizar**.
     *
     * El test escribe con `DB::table()` para saltearse cualquier defensa de la
     * aplicación: lo que se está probando es la base, no el código.
     */
    public function test_la_base_rechaza_un_segundo_hallazgo_del_mismo_turno(): void
    {
        $this->fakeDeGoogle();

        $tenant = $this->tenant();
        $turno = $this->turnoSinEvento($tenant);

        $this->conciliar();

        $this->assertNotNull($this->hallazgoDe($turno),
            'Precondición: no se generó el primer hallazgo, así que rechazar el segundo no '
            .'probaría nada.');

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('reconciliation_findings')->insert([
            'tenant_id' => $tenant->id,
            'booking_id' => $turno->id,
            'type' => ReconciliationFinding::TIPO_TURNO_SIN_EVENTO,
            'detected_at' => CarbonImmutable::now('UTC'),
            'created_at' => CarbonImmutable::now('UTC'),
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /**
     * **⭐ Un hallazgo resuelto NO vuelve a aparecer, aunque el desalineado siga.**
     *
     * Por qué importa: este es el caso que puede hacer inservible la pantalla.
     * «Mantener» **no recrea el evento en Google** —lo fija el encargo—, así que
     * después de resolver, el turno sigue vivo en la base y sigue ausente del
     * calendario: **el desalineado que lo generó no desapareció**. Si el hallazgo
     * volviera, el dueño resolvería lo mismo cada 15 minutos hasta que deje de
     * mirar la pantalla, y a partir de ahí los turnos en duda de verdad se
     * pierden entre el ruido.
     *
     * ## Cómo se resuelve: el candado retiene también estando resuelto
     *
     * `unique(tenant_id, type, booking_id)`, **sin ninguna condición sobre
     * `resolved_at`**. O sea: **un hallazgo por turno y por tipo, para siempre**,
     * lo hayan resuelto o no. La fila resuelta sigue ocupando el candado, así que
     * el `INSERT` de la corrida siguiente rebota contra la base.
     *
     * ⚠️ **Es deliberadamente el opuesto de `live_event_id`**, y vale decir por
     * qué, porque a primera vista parece la misma situación y no lo es. Allá la
     * fila cancelada tiene que **soltar** el candado: el horario se liberó y otro
     * cliente lo tiene que poder reservar. Acá la fila resuelta tiene que
     * **retenerlo**: el hallazgo no se resolvió porque el mundo cambió, se
     * resolvió porque **una persona decidió**, y esa decisión no caduca a los 15
     * minutos.
     *
     * El corolario es que el candado **no** puede ser «único entre pendientes»
     * (`resolved_at IS NULL` en una columna generada, el truco de
     * `live_event_id`): eso permitiría exactamente lo que este test prohíbe.
     *
     * La otra resolución —«cancelar»— no necesita nada de esto: el turno queda
     * `cancelled` y el barrido inverso ya excluye los cancelados. El candado es lo
     * que cubre **«mantener»**, que es el camino donde el desalineado persiste.
     */
    public function test_un_hallazgo_resuelto_no_vuelve_a_aparecer_aunque_el_desalineado_siga(): void
    {
        $this->fakeDeGoogle();

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $turno = $this->turnoSinEvento($tenant);

        $this->conciliar();

        $hallazgo = $this->hallazgoDe($turno);
        $this->assertNotNull($hallazgo, 'Precondición: la corrida no dejó ningún hallazgo.');

        $this->resolver($usuario, $hallazgo->id, 'mantener');

        // La corrida siguiente ve exactamente el mismo mundo: el evento no volvió
        // al calendario, porque mantener no recrea nada.
        $this->conciliar();

        $hallazgos = $this->hallazgosCrudos($tenant);

        $this->assertCount(1, $hallazgos,
            'La corrida siguiente volvió a generar el hallazgo que una persona ya resolvió: el '
            .'dueño lo resuelve otra vez cada 15 minutos hasta que deja de mirar la pantalla.');

        $this->assertNotNull($hallazgos[0]->resolved_at,
            'El hallazgo volvió a quedar pendiente: la corrida pisó la decisión que tomó una '
            .'persona y la pantalla lo vuelve a pedir.');

        $this->assertSame([], $this->hallazgosDeLaVista(
            $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
        ), 'La pantalla volvió a mostrar el turno que ya se resolvió.');
    }

    // ------------------------------------------------------------ la pantalla

    /**
     * **§ 8 · El panel lista los pendientes, con lo que hace falta para decidir.**
     *
     * Por qué importa: hoy la conciliación **no persiste nada** —solo escribe al
     * log— así que el desalineado es invisible para el negocio. Sin esta pantalla
     * la decisión del 2026-08-21 no existe: «queda en duda y una persona lo
     * resuelve» necesita que alguien lo pueda ver.
     *
     * **RNF-02 · La hora se muestra en la zona del negocio.** El turno arranca a
     * las 15:00 UTC, que en Bogotá son las 10:00. Un `format()` directo sobre la
     * columna mostraría 15:00 y el dueño llamaría al cliente por un turno que cree
     * que es cinco horas más tarde.
     */
    public function test_el_panel_lista_los_turnos_en_duda_pendientes(): void
    {
        $this->fakeDeGoogle();

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $this->turnoSinEvento($tenant, 'Ana Dudosa');

        $this->conciliar();

        $hallazgos = $this->hallazgosDeLaVista(
            $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
        );

        $this->assertCount(1, $hallazgos,
            'La pantalla no muestra el turno en duda: el desalineado sigue siendo invisible '
            .'para el negocio, que es lo que esta decisión vino a arreglar.');

        $this->assertSame('Ana Dudosa', $hallazgos[0]['client_name'] ?? null,
            'La fila no dice de qué cliente es el turno: sin eso no hay a quién llamar.');

        $this->assertStringContainsString('10:00', (string) ($hallazgos[0]['cuando'] ?? ''),
            'RNF-02 · La hora del turno no se muestra en la zona del negocio.');

        $this->assertStringNotContainsString('15:00', (string) ($hallazgos[0]['cuando'] ?? ''),
            'RNF-02 · Se le está mostrando UTC a un humano: llamaría al cliente por un turno '
            .'que cree que es cinco horas más tarde.');
    }

    /**
     * **Un hallazgo ya resuelto no ocupa lugar en la pantalla.**
     *
     * Por qué importa: la pantalla es una bandeja de trabajo, no un historial. Si
     * lo resuelto se quedara ahí, cada semana costaría más encontrar lo que sí
     * hay que hacer, y el dueño terminaría igual que si no hubiera pantalla.
     *
     * El pendiente que lo acompaña es el canario: sin él, «no muestra lo resuelto»
     * lo pasa una pantalla que no muestra nada.
     */
    public function test_el_panel_no_lista_los_hallazgos_ya_resueltos(): void
    {
        $this->fakeDeGoogle();

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $resuelto = $this->turnoSinEvento($tenant, 'Ya Resuelta', 'evt_resuelto');
        $pendiente = $this->turnoSinEvento($tenant, 'Sigue Pendiente', 'evt_pendiente');

        $this->conciliar();

        $this->resolver($usuario, $this->hallazgoDe($resuelto)?->id, 'mantener');

        $nombres = array_map(
            fn (array $h) => (string) ($h['client_name'] ?? ''),
            $this->hallazgosDeLaVista(
                $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
            ),
        );

        $this->assertContains('Sigue Pendiente', $nombres,
            'Canario: la pantalla no muestra ni el hallazgo que sigue pendiente, así que lo que '
            .'este test afirma sobre los resueltos no distingue nada.');

        $this->assertNotContains('Ya Resuelta', $nombres,
            'La pantalla sigue mostrando lo que alguien ya resolvió: la bandeja se vuelve un '
            .'historial y encontrar lo que hay que hacer cuesta más cada semana.');

        $this->assertNotNull($this->hallazgoDe($pendiente),
            'Precondición: el hallazgo pendiente ni siquiera existe.');
    }

    // ----------------------------------------------------------- resolverlo

    /**
     * **Mantener deja el turno vivo y cierra el hallazgo, con quién y cuándo.**
     *
     * Por qué importa: es la mitad del caso que motivó todo el cambio. El dueño
     * borró el evento por error, o lo movió a otro calendario, y el turno **va a
     * ocurrir**: cancelarlo automáticamente le habría hecho perder un cliente que
     * ya tenía la confirmación en el celular.
     *
     * **Queda quién y cuándo**, igual que `attendance_marked_by` en T-036: cuando
     * dentro de dos semanas el cliente llegue y no haya turno, la única forma de
     * reconstruir qué pasó es saber quién decidió mantenerlo.
     *
     * ⚠️ **Mantener no recrea el evento en Google.** Lo fija el encargo: si el
     * dueño lo quiere de vuelta en su calendario, es otro ciclo. Por eso este test
     * no afirma nada sobre llamadas a Google.
     */
    public function test_mantener_deja_el_turno_vivo_y_registra_quien_lo_resolvio(): void
    {
        $this->fakeDeGoogle();

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $turno = $this->turnoSinEvento($tenant);

        $this->conciliar();

        $this->resolver($usuario, $this->hallazgoDe($turno)?->id, 'mantener');

        $fila = DB::table('bookings')->where('id', $turno->id)->first();

        $this->assertSame(Booking::ESTADO_AGENDADO, $fila?->status,
            'Se mantuvo el turno y quedó cancelado igual: es exactamente el resultado que la '
            .'decisión del 2026-08-21 vino a impedir.');

        $this->assertSame(Booking::ASISTENCIA_PENDIENTE, $fila?->attendance,
            'Resolver un hallazgo tocó la asistencia, que es de T-036 y tiene su propia '
            .'auditoría de quién la marcó.');

        $hallazgo = $this->hallazgoDe($turno);

        $this->assertNotNull($hallazgo?->resolved_at,
            'El hallazgo quedó pendiente después de resolverlo: vuelve a la pantalla y el dueño '
            .'lo resuelve otra vez.');

        $this->assertSame((string) $usuario->id, (string) $hallazgo->resolved_by,
            'No quedó quién lo resolvió: cuando el cliente llegue y no haya turno, nadie va a '
            .'poder reconstruir quién decidió qué.');

        $this->assertSame(ReconciliationFinding::RESOLUCION_MANTENIDO, $hallazgo->resolution,
            'No quedó qué se decidió: «resuelto» sin decir cómo no distingue mantener de '
            .'cancelar, que son resultados opuestos para el cliente.');
    }

    /**
     * **Cancelar cancela el turno, y también queda quién y cuándo.**
     *
     * Por qué importa: es la otra mitad. Acá el dueño confirma lo que ya había
     * hecho en su calendario —el cliente lo llamó por teléfono y se dio de baja—.
     * La diferencia con el comportamiento viejo no es el resultado sino **quién lo
     * decide**: antes lo decidía la corrida sola, ahora lo decide una persona que
     * puede llamar al cliente desde el número de atención humana antes de hacerlo.
     *
     * ⚠️ **Ningún aviso automático al cliente**, y por eso este test afirma
     * también que no salió ni un mensaje: mandarlo requeriría una plantilla
     * aprobada de Meta que no existe, y el contacto lo hace una persona.
     */
    public function test_cancelar_cancela_el_turno_y_registra_quien_lo_resolvio(): void
    {
        $this->fakeDeGoogle();

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $turno = $this->turnoSinEvento($tenant);

        $this->conciliar();

        $this->resolver($usuario, $this->hallazgoDe($turno)?->id, 'cancelar');

        $fila = DB::table('bookings')->where('id', $turno->id)->first();

        $this->assertSame(Booking::ESTADO_CANCELADO, $fila?->status,
            'Se pidió cancelar el turno y sigue vivo: ocupa un horario que en el calendario del '
            .'dueño ya está libre y ningún otro cliente lo puede reservar.');

        $this->assertSame(Booking::ASISTENCIA_PENDIENTE, $fila?->attendance,
            'Cancelar desde la pantalla marcó al cliente como ausente: nadie lo esperó, y esa '
            .'tasa es el número con el que se vende el producto (T-036).');

        $hallazgo = $this->hallazgoDe($turno);

        $this->assertNotNull($hallazgo?->resolved_at,
            'El hallazgo quedó pendiente después de resolverlo.');

        $this->assertSame((string) $usuario->id, (string) $hallazgo->resolved_by,
            'No quedó quién canceló el turno: es la decisión más cara de la pantalla y es la '
            .'que más falta hace poder auditar.');

        $this->assertSame(ReconciliationFinding::RESOLUCION_CANCELADO, $hallazgo->resolution,
            'No quedó qué se decidió: mantener y cancelar son resultados opuestos para el '
            .'cliente y el registro tiene que poder distinguirlos.');

        $aMeta = array_filter(
            Http::recorded()->all(),
            fn ($par) => str_contains($par[0]->url(), 'graph.facebook.com'),
        );

        $this->assertSame([], array_values($aMeta),
            'Se le mandó algo al cliente al cancelar: la decisión es que el contacto lo hace '
            .'una persona desde el número humano, porque fuera de las 24 h de Meta haría falta '
            .'una plantilla aprobada que no existe.');
    }

    // --------------------------------------------------- aislamiento (RNF-01)

    /**
     * **RNF-01 · El panel de una PyME no ve los turnos en duda de otra.**
     *
     * Por qué importa: cada fila lleva el nombre del cliente y la hora de su
     * turno. Verlas es ver la clientela de otro negocio — la fuga que RNF-01
     * existe para impedir.
     *
     * Va con canario: primero se verifica que la PyME dueña **sí** ve el suyo,
     * porque si no, «la otra no lo ve» sería cierto en una pantalla vacía.
     */
    public function test_el_panel_de_una_pyme_no_lista_los_turnos_en_duda_de_otra(): void
    {
        $this->fakeDeGoogle();

        $mia = $this->tenant('mia', '1111111111');
        $ajena = $this->tenant('ajena', '2222222222');

        $this->turnoSinEvento($mia, 'Cliente Propia');
        $this->turnoSinEvento($ajena, 'Cliente Ajena');

        $this->conciliar();

        $nombres = array_map(
            fn (array $h) => (string) ($h['client_name'] ?? ''),
            $this->hallazgosDeLaVista(
                $this->actingAs($this->usuario($mia))->get(self::PANEL)->assertSuccessful(),
            ),
        );

        $this->assertContains('Cliente Propia', $nombres,
            'Canario: la PyME no ve ni su propio turno en duda.');

        $this->assertNotContains('Cliente Ajena', $nombres,
            'Se filtró al panel la clienta de otra PyME, con su nombre y la hora de su turno.');
    }

    /**
     * **RNF-01 · Nadie resuelve por URL directa el hallazgo de otra PyME.**
     *
     * Por qué importa: resolver **cancela un turno real**. Un `cancelar` cruzado
     * le da de baja a otro negocio un turno que iba a ocurrir, y el cliente llega
     * igual porque nadie le avisa nada.
     *
     * **403 y no 404, con registro del intento**, igual que `AsistenciaController`:
     * consultado *con* el Global Scope, el hallazgo ajeno sería indistinguible de
     * un id que no existe y el intento no se podría auditar. Por eso la búsqueda
     * va **sin** scope y la comparación de tenants es explícita.
     *
     * ⚠️ La comparación tiene que ser **como string**: `tenant_id` es UUID y un
     * `(int)` sobre un UUID devuelve `1` para todos, con lo que el chequeo pasaría
     * siempre y la fuga seguiría abierta sin que nada avise.
     */
    public function test_no_se_puede_resolver_por_url_el_turno_en_duda_de_otra_pyme(): void
    {
        $this->fakeDeGoogle();

        $mia = $this->tenant('mia', '1111111111');
        $ajena = $this->tenant('ajena', '2222222222');

        $turnoAjeno = $this->turnoSinEvento($ajena, 'Cliente Ajena');

        $this->conciliar();

        $hallazgoAjeno = $this->hallazgoDe($turnoAjeno);
        $this->assertNotNull($hallazgoAjeno, 'Precondición: no hay hallazgo ajeno que atacar.');

        $this->resolver($this->usuario($mia), $hallazgoAjeno->id, 'cancelar')->assertForbidden();

        $this->assertSame(Booking::ESTADO_AGENDADO,
            DB::table('bookings')->where('id', $turnoAjeno->id)->value('status'),
            'Se canceló el turno de otra PyME desde el panel equivocado: el cliente llega igual '
            .'porque nadie le avisa nada.');

        $this->assertNull($this->hallazgoDe($turnoAjeno)?->resolved_at,
            'El hallazgo de la otra PyME quedó resuelto: desaparece de su pantalla sin que nadie '
            .'de ese negocio haya decidido nada.');

        $this->assertNotSame([], $this->cruzados,
            'El intento cruzado no dejó rastro: un acceso a datos de otra PyME que no se '
            .'registra no se puede auditar (AC-14.2).');

        // Como string: `tenant_id` es UUID y un `(int)` devuelve 1 para todos.
        $this->assertSame($ajena->id, (string) ($this->cruzados[0]['tenant_id_objetivo'] ?? ''),
            'El registro no dice a qué PyME se intentó entrar.');
    }

    // ------------------------------------------------------- quién resuelve

    /**
     * **Un `staff` puede resolver un turno en duda.**
     *
     * Es el mismo criterio que sostienen `asistencia`, `recordatorios-fallidos`
     * y la pausa desde el panel: va con `rol:atender` y no con `rol:configurar`,
     * porque **quien está en el mostrador es quien sabe si el turno va**. Un
     * desalineado que solo puede resolver el dueño se queda sin resolver los
     * días que el dueño no entra — y cada uno es un cliente que aparece en la
     * puerta o un horario que nadie recupera.
     *
     * ⚠️ Este test se escribe porque el comportamiento **ya era el correcto y no
     * lo protegía nada**: los nueve tests del archivo usan `owner`, que puede
     * todo, así que endurecer la ruta a `rol:configurar` los dejaba a los nueve
     * en verde. Es la misma forma de los defectos que aparecieron todo el día —
     * código correcto sin cobertura.
     */
    public function test_un_staff_puede_resolver_un_turno_en_duda(): void
    {
        // Sin el doble, la conciliación no ve ningún calendario y no hay hallazgo
        // que resolver: el test pasaría a medir otra cosa.
        $this->fakeDeGoogle();

        $tenant = $this->tenant();
        $staff = $this->usuario($tenant, Role::Staff);

        $turno = $this->turnoSinEvento($tenant);
        $this->conciliar();

        $hallazgo = $this->hallazgoDe($turno);
        $this->assertNotNull($hallazgo,
            'Precondición: la conciliación no dejó el hallazgo, así que no hay nada que resolver.');

        $respuesta = $this->resolver($staff, $hallazgo->id, 'mantener');

        $this->assertNotSame(403, $respuesta->status(),
            'Un staff no pudo resolver un turno en duda: atender no es configurar. Quien está en '
            .'el mostrador es quien sabe si ese turno va.');

        $this->assertNotNull($this->hallazgoDe($turno)?->resolved_at,
            'El staff resolvió y el hallazgo sigue pendiente: va a volver a aparecer en la bandeja.');
    }

    /** Y también lo **ve**: resolver algo que no aparece en la pantalla no sirve de nada. */
    public function test_un_staff_ve_la_pantalla_de_turnos_en_duda(): void
    {
        // Sin el doble, la conciliación no ve ningún calendario y no hay hallazgo
        // que resolver: el test pasaría a medir otra cosa.
        $this->fakeDeGoogle();

        $tenant = $this->tenant();
        $staff = $this->usuario($tenant, Role::Staff);

        $this->turnoSinEvento($tenant);
        $this->conciliar();

        $respuesta = $this->actingAs($staff)->get(self::PANEL);

        $this->assertNotSame(403, $respuesta->status(),
            'Un staff no puede abrir la pantalla de turnos en duda, así que no puede resolver lo '
            .'que sí tiene permiso de resolver.');

        $this->assertNotEmpty($this->hallazgosDeLaVista($respuesta),
            'La pantalla abrió para el staff pero sin el hallazgo pendiente: lo mismo que no verla.');
    }
}
