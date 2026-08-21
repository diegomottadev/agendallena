<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * T-038 · Registro de asistencia y tasa de ausentismo (US-17).
 *
 * La columna `attendance` ya existe (T-036) y `BookingAttendanceTest` cubre el
 * esquema. Acá se prueba **lo que todavía no existe**: la pantalla del panel que
 * la escribe, la lista de pendientes, la tasa por rango y el cruce con la
 * respuesta al recordatorio.
 *
 * Es el ticket que convierte el MVP en un experimento: sin este dato se sabe
 * cuántos botones se tocaron, pero no si la gente fue.
 *
 * ## Contrato que estos tests fijan
 *
 * Ningún criterio de aceptación dice URLs ni nombres de variables. Los elijo acá
 * —es lo que hace falta para poder escribir un test— y quedan escritos para que
 * el implementador los siga:
 *
 * | | |
 * | :-- | :-- |
 * | `GET /panel/asistencia` | Pendientes de marcar. Variable de vista `pendientes` |
 * | `GET /panel/asistencia?desde=&hasta=` | Suma `resumen` y `marcados` |
 * | `POST /panel/asistencia/{booking}/marcar` | Cuerpo `asistencia=attended\|no_show` |
 *
 * `resumen` lleva `turnos`, `asistidos`, `ausentes` y `tasa_ausentismo`.
 * Cada entrada de `pendientes` y `marcados` lleva al menos `id` y `client_name`;
 * las de `marcados` suman `attendance` y `respuesta_recordatorio`.
 *
 * ## Ambigüedades del criterio que tuve que resolver
 *
 * ⚠️ **Desde cuándo un turno está "pasado".** AC-17.1 dice *"ya pasó su
 * horario"* y no fija margen. Los dos extremos que no admiten discusión se
 * afirmaron desde el principio: un turno **de ayer** es marcable y aparece
 * pendiente, y uno **de mañana** no. **El turno en curso —ya empezó, todavía no
 * terminó— quedó sin test a propósito** mientras elegir entre
 * `start_time <= ahora` y `end_time <= ahora` fuera una decisión de producto sin
 * tomar.
 *
 * **Se tomó el 2026-08-21:** se marca **apenas empieza**, o sea contra
 * `start_time` (§ 6 de `plan-for-diego/decisiones-tomadas.md`). Lo fijan
 * `test_un_turno_en_curso_se_puede_marcar_como_asistido` y su par de ausente.
 * De yapa cae del lado barato del último criterio: el índice de T-009 es
 * `(tenant_id, start_time, status)`, así que filtrar por `start_time` lo
 * aprovecha una columna más que filtrar por `end_time`.
 *
 * ⚠️ **Sobre qué se calcula la tasa.** El criterio pide *"cantidad de turnos,
 * asistidos, ausentes y la tasa resultante"* sin decir el denominador. Acá se
 * afirma **ausentes / (asistidos + ausentes)**: los turnos sin marcar no son
 * asistencias, son datos que no tenemos, y meterlos en el denominador hace que
 * la tasa **baje cuando el dueño marca menos** — o sea que el número que
 * sostiene el precio del producto mejoraría solo. El escenario del test está
 * armado para que las dos lecturas den distinto (25% contra 16,7%).
 *
 * ⚠️ **`cancelado` y `re-agendado` de AC-17.4 no son alcanzables hoy.** El
 * ticket pide distinguir cuatro respuestas al recordatorio; estos tests afirman
 * tres. Está explicado en `test_se_distingue_al_que_no_respondio_...`.
 */
class RegistroDeAsistenciaTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private const URL = '/panel/asistencia';

    /**
     * ⚠️ Bogotá y no Buenos Aires **a propósito**: UTC-5 contra UTC-3, offset
     * distinto hoy y sin horario de verano. Con dos zonas del mismo offset, el
     * test del rango pasa por casualidad aunque el código compare fechas UTC.
     */
    private const TZ = 'America/Bogota';

    /** Un instante fijo: sin esto, "pasado" cambia según la hora en que corras. */
    private const AHORA = '2026-08-20 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \App\Support\TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ andamiaje

    private function tenant(string $slug = 'piloto'): Tenant
    {
        return Tenant::create([
            'name' => 'Peluquería '.$slug,
            'slug' => $slug.'-'.uniqid(),
            'status' => 'active',
            'timezone' => self::TZ,
        ]);
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

    private function integracion(Tenant $tenant): int
    {
        return Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'cal-'.uniqid(),
            'status' => 'connected',
        ])->id;
    }

    /**
     * Un turno del tenant. `$inicioLocal` va **en la zona del negocio** —así se
     * piensan los turnos— y se guarda en UTC, como exige RNF-02.
     */
    private function turno(
        Tenant $tenant,
        string $inicioLocal,
        string $cliente = 'Cliente',
        string $status = 'scheduled',
        string $attendance = 'pending',
        ?int $integracion = null,
    ): int {
        $inicio = CarbonImmutable::parse($inicioLocal, self::TZ)->utc();

        return DB::table('bookings')->insertGetId([
            'tenant_id' => $tenant->id,
            'integration_id' => $integracion ?? $this->integracion($tenant),
            'external_event_id' => 'evt-'.uniqid(),
            'client_name' => $cliente,
            'client_phone' => '573001112233',
            'service_name' => 'corte',
            'start_time' => $inicio->format('Y-m-d H:i:s'),
            'end_time' => $inicio->addMinutes(30)->format('Y-m-d H:i:s'),
            'status' => $status,
            'attendance' => $attendance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** El recordatorio t-24h que se le mandó a ese turno (T-026 / T-037). */
    private function recordatorioEnviado(Tenant $tenant, int $bookingId): void
    {
        DB::table('notification_logs')->insert([
            'tenant_id' => $tenant->id,
            'booking_id' => $bookingId,
            'type' => 'reminder_24h',
            'whatsapp_message_id' => 'wamid.'.uniqid(),
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function marcar(User $usuario, int $bookingId, string $asistencia): TestResponse
    {
        return $this->actingAs($usuario)
            ->post(self::URL."/{$bookingId}/marcar", ['asistencia' => $asistencia]);
    }

    /** La fila cruda: nunca a través del modelo, para leer lo que quedó guardado. */
    private function fila(int $id): object
    {
        return DB::table('bookings')->find($id);
    }

    /**
     * Una variable de la vista, siempre como array de arrays.
     *
     * @return array<int,array<string,mixed>>
     */
    private function listaDeLaVista(TestResponse $respuesta, string $clave): array
    {
        $respuesta->assertViewHas($clave);

        $valor = $respuesta->viewData($clave);

        if ($valor instanceof Collection) {
            $valor = $valor->all();
        }

        return array_values(array_map(
            fn ($fila) => $fila instanceof Model ? $fila->toArray() : (array) $fila,
            (array) $valor,
        ));
    }

    /** @return array<string,mixed>|null */
    private function buscar(array $lista, int $id): ?array
    {
        foreach ($lista as $fila) {
            if ((int) ($fila['id'] ?? 0) === $id) {
                return $fila;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function resumen(User $usuario, string $desde, string $hasta): array
    {
        $respuesta = $this->actingAs($usuario)
            ->get(self::URL."?desde={$desde}&hasta={$hasta}")
            ->assertSuccessful()
            ->assertViewHas('resumen');

        $valor = $respuesta->viewData('resumen');

        return $valor instanceof Collection ? $valor->all() : (array) $valor;
    }

    // ------------------------------------------- AC-17.1 · marcar en un clic

    /**
     * AC-17.1 · Un turno que ya pasó se marca como asistido.
     *
     * "Un solo clic" se prueba como **una sola petición que ya deja el dato
     * guardado**: sin formulario intermedio ni pantalla de confirmación.
     */
    public function test_un_turno_pasado_se_marca_como_asistido_con_una_sola_peticion(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $id = $this->turno($tenant, '2026-08-19 10:00', 'Marisa');

        $this->marcar($usuario, $id, 'attended')->assertRedirect();

        $this->assertSame('attended', $this->fila($id)->attendance,
            'Una sola petición no dejó marcada la asistencia: AC-17.1 pide un clic, no un formulario.');
    }

    /** AC-17.1 · Y como ausente, que es la mitad que le importa al KPI. */
    public function test_un_turno_pasado_se_marca_como_ausente_con_una_sola_peticion(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $id = $this->turno($tenant, '2026-08-19 10:00', 'Marisa');

        $this->marcar($usuario, $id, 'no_show')->assertRedirect();

        $this->assertSame('no_show', $this->fila($id)->attendance);
    }

    /**
     * El caso que justifica la columna separada de T-036: `confirmed` +
     * `no_show` a la vez. Si marcar la ausencia pisa el estado de la reserva,
     * AC-17.4 se vuelve imposible — se pierde justo el dato de que había
     * confirmado.
     */
    public function test_marcar_la_asistencia_no_pisa_el_estado_de_la_reserva(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $id = $this->turno($tenant, '2026-08-19 10:00', 'Marisa', status: 'confirmed');

        $this->marcar($usuario, $id, 'no_show');

        $fila = $this->fila($id);

        $this->assertSame('confirmed', $fila->status,
            'Marcar la ausencia pisó el estado de la reserva y se perdió que había confirmado.');
        $this->assertSame('no_show', $fila->attendance);
    }

    /**
     * Auditoría: la columna de T-036 existe, pero nadie la escribe todavía.
     * Sin esto no se distingue una fila nunca tocada de una marcada por error.
     */
    public function test_marcar_registra_quien_lo_hizo_y_cuando(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $id = $this->turno($tenant, '2026-08-19 10:00');

        $this->marcar($usuario, $id, 'attended');

        $fila = $this->fila($id);

        $this->assertSame((int) $usuario->id, (int) $fila->attendance_marked_by,
            'No quedó registrado quién marcó la asistencia.');
        $this->assertNotNull($fila->attendance_marked_at,
            'No quedó registrado cuándo se marcó la asistencia.');
    }

    /**
     * § 6 de `plan-for-diego/decisiones-tomadas.md` · **Un turno que está
     * ocurriendo ahora mismo se marca apenas empieza.**
     *
     * En una peluquería el dueño marca cuando la persona **se sienta**, no
     * cuando se va: si hay que esperar a que el turno termine, el momento de
     * marcar cae justo cuando entra el cliente siguiente, y no se marca nunca.
     * Y como la tasa de ausentismo se calcula **solo sobre los turnos
     * marcados** (§ 1), todo lo que no se marca desaparece del número que
     * sostiene el precio del producto: cuanto más fácil sea marcar, más
     * confiable es la métrica. Por eso la comparación va contra el **inicio**.
     *
     * ⚠️ **Por qué el instante elegido no es ambiguo de huso.** El tenant es
     * Bogotá (UTC-5, sin horario de verano) y el "ahora" congelado del `setUp`
     * es `2026-08-20 15:00 UTC`, o sea **las 10:00 en el negocio**. El turno va
     * de `09:40` a `10:10` locales — `14:40` a `15:10` UTC. Las dos lecturas
     * equivocadas caen **las dos del lado del rechazo**, así que ninguna deja
     * pasar el test por casualidad:
     *
     * - Comparar contra el **fin** (`15:10 UTC`) lo ve futuro y lo rechaza.
     * - Confundir el UTC guardado con hora local (`14:40` contra las `10:00` de
     *   Bogotá) también lo ve futuro y lo rechaza.
     *
     * Las cinco horas de diferencia entre las dos zonas son diez veces la
     * ventana del turno: no hay forma de que coincidan, que es exactamente lo
     * que sí pasó con Santiago y Buenos Aires en el test de husos de T-031.
     */
    public function test_un_turno_en_curso_se_puede_marcar_como_asistido(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $id = $this->turno($tenant, '2026-08-20 09:40', 'Recién se sentó');

        $this->assertTurnoEnCurso($id);

        $this->marcar($usuario, $id, 'attended')->assertRedirect();

        $this->assertSame('attended', $this->fila($id)->attendance,
            'No se pudo marcar como asistido un turno que ya empezó y todavía no terminó: '
            .'§ 6 pide marcarlo apenas empieza, sin esperar a que termine.');
    }

    /**
     * § 6 · La otra mitad de la decisión, y la que le importa al KPI.
     *
     * Un turno en curso también se marca **ausente**: el cliente no apareció a
     * la hora y el dueño ya lo sabe sin esperar a que pase la media hora. Si
     * solo se pudiera marcar la asistencia y no la ausencia, la lista de
     * pendientes se llenaría justo con los casos que forman el numerador de la
     * tasa de ausentismo — el número quedaría sesgado a la baja por diseño.
     *
     * El instante es el mismo que el test anterior y no es ambiguo por el mismo
     * motivo, explicado ahí.
     */
    public function test_un_turno_en_curso_se_puede_marcar_como_ausente(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $id = $this->turno($tenant, '2026-08-20 09:40', 'No apareció a la hora');

        $this->assertTurnoEnCurso($id);

        $this->marcar($usuario, $id, 'no_show')->assertRedirect();

        $this->assertSame('no_show', $this->fila($id)->attendance,
            'No se pudo marcar como ausente un turno en curso: el numerador de la tasa de '
            .'ausentismo queda esperando a que el turno termine.');
    }

    /**
     * El turno está **en curso de verdad**: leído crudo de la base y en UTC, ya
     * empezó y todavía no terminó.
     *
     * Va como afirmación y no como comentario porque el andamiaje puede cambiar
     * —la duración fija de 30 minutos del helper, la zona del tenant— y sin
     * esto los dos tests de arriba pasarían igual probando el caso de al lado,
     * que ya está cubierto por `test_un_turno_pasado_se_marca_como_asistido...`.
     */
    private function assertTurnoEnCurso(int $id): void
    {
        $fila = $this->fila($id);
        $ahora = CarbonImmutable::now('UTC');
        $inicio = CarbonImmutable::parse($fila->start_time, 'UTC');
        $fin = CarbonImmutable::parse($fila->end_time, 'UTC');

        $this->assertTrue($inicio->lessThan($ahora),
            "El turno todavía no empezó ({$inicio} contra {$ahora} UTC): esto no prueba un turno en curso.");
        $this->assertTrue($fin->greaterThan($ahora),
            "El turno ya terminó ({$fin} contra {$ahora} UTC): esto prueba un turno pasado, no uno en curso.");
    }

    /**
     * AC-17.1 dice "un turno que ya pasó". Uno de mañana, no.
     *
     * ⚠️ **Va con control positivo en el mismo test, y es a propósito.** Un test
     * que solo afirmara "el turno futuro quedó en `pending`" **pasa en verde sin
     * que exista nada**: sin ruta, la petición da 404 y el turno queda en
     * `pending` igual. Marcar primero uno pasado obliga a que el camino feliz
     * exista para que el rechazo signifique algo.
     *
     * No se afirma el código HTTP del rechazo —el criterio no lo fija—, solo que
     * el dato no se escribe y que la app no revienta.
     */
    public function test_un_turno_que_todavia_no_empezo_no_se_puede_marcar(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $integracion = $this->integracion($tenant);

        $pasado = $this->turno($tenant, '2026-08-19 10:00', 'De ayer', integracion: $integracion);
        $futuro = $this->turno($tenant, '2026-08-21 10:00', 'Del futuro', integracion: $integracion);

        // Control positivo: si esto no marca, el rechazo de abajo no prueba nada.
        $this->marcar($usuario, $pasado, 'attended');
        $this->assertSame('attended', $this->fila($pasado)->attendance,
            'El camino feliz no funciona, así que el rechazo del turno futuro no significa nada.');

        $respuesta = $this->marcar($usuario, $futuro, 'attended');

        $this->assertSame('pending', $this->fila($futuro)->attendance,
            'Se marcó la asistencia de un turno que todavía no ocurrió.');
        $this->assertLessThan(500, $respuesta->status(),
            'Rechazar un turno futuro tiró un error del servidor en vez de rechazarlo.');
    }

    /**
     * ⚠️ **Marcar asistencia es atender, no configurar.**
     *
     * Es la persona que estuvo en el mostrador la que sabe si el cliente vino.
     * Si esto exige `rol:configurar`, el dato lo carga quien no lo tiene.
     * Definición: `.claude/docs/01-producto/05-roles-y-permisos.md`.
     */
    public function test_un_staff_puede_marcar_asistencia(): void
    {
        $tenant = $this->tenant();
        $staff = $this->usuario($tenant, Role::Staff);
        $id = $this->turno($tenant, '2026-08-19 10:00');

        $respuesta = $this->marcar($staff, $id, 'attended');

        $this->assertNotSame(403, $respuesta->status(),
            'Un staff no pudo marcar asistencia: atender no es configurar (H-13).');
        $this->assertSame('attended', $this->fila($id)->attendance);
    }

    /**
     * RNF-01 · AC-14.2 · Aislamiento entre PyMEs, con el 403 y el rastro que
     * exige el precedente de `ConversacionesController`.
     *
     * La comparación de `tenant_id` va **como string**: es UUID, y un `(int)`
     * sobre un UUID devuelve `1` para todos — con eso, este test pasaría incluso
     * con la fuga presente.
     */
    public function test_no_se_puede_marcar_un_turno_de_otro_tenant(): void
    {
        $mio = $this->tenant('a');
        $ajeno = $this->tenant('b');

        $this->assertNotSame((string) $mio->id, (string) $ajeno->id);

        $usuario = $this->usuario($mio);
        $id = $this->turno($ajeno, '2026-08-19 10:00', 'Cliente de la otra PyME');

        $this->marcar($usuario, $id, 'no_show')->assertForbidden();

        $this->assertSame('pending', $this->fila($id)->attendance,
            'Un usuario marcó la asistencia de un turno de otra PyME.');
    }

    // ---------------------------------------- AC-17.2 · pendientes agrupados

    /** AC-17.2 · Los pasados sin marcar aparecen solos, sin buscarlos. */
    public function test_los_turnos_pasados_sin_marcar_aparecen_agrupados_como_pendientes(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $ayer = $this->turno($tenant, '2026-08-19 10:00', 'Marisa');
        $anteayer = $this->turno($tenant, '2026-08-18 16:00', 'Rubén');

        $respuesta = $this->actingAs($usuario)->get(self::URL)->assertSuccessful();

        $pendientes = $this->listaDeLaVista($respuesta, 'pendientes');

        $this->assertNotNull($this->buscar($pendientes, $ayer));
        $this->assertNotNull($this->buscar($pendientes, $anteayer));

        // "Sin tener que buscarlos" también significa que se leen en pantalla.
        $respuesta->assertSee('Marisa')->assertSee('Rubén');
    }

    /** AC-17.2 · Marcado y listo: deja de pedir atención. */
    public function test_un_turno_ya_marcado_no_sigue_pendiente(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $marcado = $this->turno($tenant, '2026-08-19 10:00', 'Marisa', attendance: 'attended');
        $sinMarcar = $this->turno($tenant, '2026-08-19 11:00', 'Rubén');

        $pendientes = $this->listaDeLaVista(
            $this->actingAs($usuario)->get(self::URL)->assertSuccessful(), 'pendientes'
        );

        $this->assertNull($this->buscar($pendientes, $marcado),
            'Un turno ya marcado sigue apareciendo como pendiente.');
        $this->assertNotNull($this->buscar($pendientes, $sinMarcar));
    }

    /**
     * § 6 · **El turno en curso aparece en la lista donde el dueño marca.**
     *
     * ⚠️ **Esto fija un acoplamiento que hoy nada protege.** La lista de
     * pendientes y el POST que marca **tienen que decidir por la misma
     * columna**: `start_time`. Los dos tests de más arriba solo afirman que un
     * turno en curso *se puede* marcar; poder marcarlo no sirve de nada si el
     * turno no aparece en la única pantalla desde la que se marca.
     *
     * **Qué se rompe si divergen** —por ejemplo, si mañana alguien "arregla" la
     * lista para filtrar por `end_time`— es lo más caro que puede pasarle a
     * este ticket, y es **silencioso**: la petición seguiría funcionando y todos
     * los demás tests seguirían verdes, pero el dueño no vería el turno en el
     * momento en que la persona se sienta. Para cuando el turno termine, ya
     * entró el cliente siguiente y no se marca nunca. Y como la tasa de
     * ausentismo se calcula **solo sobre los turnos marcados** (§ 1), ese turno
     * **desaparece de la métrica sin que nadie note nada**: no falla, no avisa,
     * solo deja de contar.
     *
     * ⚠️ **Va con control negativo en el mismo test, y es a propósito.** Una
     * lista que devolviera todo haría pasar la afirmación positiva sola. El
     * turno de mañana obliga a que la lista esté discriminando de verdad, y el
     * discriminador es exactamente el inicio.
     *
     * El instante es el mismo del par de tests de marcado y no es ambiguo por
     * el mismo motivo, explicado allá: tenant en Bogotá (UTC-5 sin horario de
     * verano), `ahora` a las `15:00 UTC` = `10:00` locales, turno de `09:40` a
     * `10:10` locales. Filtrar por el fin o confundir el UTC guardado con hora
     * local dejan las dos al turno del lado de "todavía no empezó", así que las
     * dos sacan al turno de la lista y ponen este test en rojo.
     */
    public function test_un_turno_en_curso_aparece_entre_los_pendientes(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $integracion = $this->integracion($tenant);

        $enCurso = $this->turno($tenant, '2026-08-20 09:40', 'Recién se sentó', integracion: $integracion);
        $futuro = $this->turno($tenant, '2026-08-21 10:00', 'Del futuro', integracion: $integracion);

        $this->assertTurnoEnCurso($enCurso);

        $respuesta = $this->actingAs($usuario)->get(self::URL)->assertSuccessful();
        $pendientes = $this->listaDeLaVista($respuesta, 'pendientes');

        $this->assertNotNull($this->buscar($pendientes, $enCurso),
            'El turno que está ocurriendo ahora mismo no aparece en la lista de pendientes: '
            .'se puede marcar por POST pero el dueño no lo ve, así que en la práctica no se marca '
            .'y el turno desaparece de la tasa de ausentismo.');

        // Control negativo: sin esto, una lista que devuelva todo pasa igual.
        $this->assertNull($this->buscar($pendientes, $futuro),
            'La lista trajo un turno que todavía no empezó, así que no está discriminando por el inicio '
            .'y la afirmación de arriba no prueba nada.');

        // "Sin tener que buscarlos" (AC-17.2) también significa que se lee en pantalla.
        $respuesta->assertSee('Recién se sentó');
    }

    /** AC-17.2 · Nadie puede decir si vino a un turno de mañana. */
    public function test_un_turno_futuro_no_aparece_entre_los_pendientes(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $futuro = $this->turno($tenant, '2026-08-21 10:00', 'Del futuro');

        $pendientes = $this->listaDeLaVista(
            $this->actingAs($usuario)->get(self::URL)->assertSuccessful(), 'pendientes'
        );

        $this->assertNull($this->buscar($pendientes, $futuro),
            'Un turno que todavía no ocurrió aparece pidiendo que le marquen la asistencia.');
    }

    /**
     * Un turno cancelado no tiene asistencia que registrar: el cliente avisó y
     * el horario se liberó. Dejarlo pendiente para siempre es la vía más rápida
     * a que el dueño abandone la lista — y con la lista, el dato.
     *
     * ⚠️ El criterio no lo dice; lo derivo de que `cancelled` es un estado
     * terminal de la reserva (T-037, AC-10.3).
     */
    public function test_un_turno_cancelado_no_queda_pendiente_de_marcar(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $cancelado = $this->turno($tenant, '2026-08-19 10:00', 'Canceló', status: 'cancelled');

        $pendientes = $this->listaDeLaVista(
            $this->actingAs($usuario)->get(self::URL)->assertSuccessful(), 'pendientes'
        );

        $this->assertNull($this->buscar($pendientes, $cancelado),
            'Un turno cancelado quedó pidiendo que le marquen la asistencia.');
    }

    /** RNF-01 · La lista de una PyME no puede traer turnos de otra. */
    public function test_los_pendientes_de_una_pyme_no_traen_turnos_de_otra(): void
    {
        $mio = $this->tenant('a');
        $ajeno = $this->tenant('b');

        $this->assertNotSame((string) $mio->id, (string) $ajeno->id);

        $usuario = $this->usuario($mio);
        $propio = $this->turno($mio, '2026-08-19 10:00', 'Marisa');
        $deLaOtra = $this->turno($ajeno, '2026-08-19 10:00', 'Cliente de la otra PyME');

        $respuesta = $this->actingAs($usuario)->get(self::URL)->assertSuccessful();
        $pendientes = $this->listaDeLaVista($respuesta, 'pendientes');

        $this->assertNotNull($this->buscar($pendientes, $propio));
        $this->assertNull($this->buscar($pendientes, $deLaOtra),
            'La lista de pendientes trajo un turno de otra PyME.');
        $respuesta->assertDontSee('Cliente de la otra PyME');
    }

    // ------------------------------------------------- AC-17.3 · la tasa

    /**
     * AC-17.3 · Es literalmente el KPI del lean canvas: sin este número no hay
     * forma de saber si el producto hizo lo que promete.
     */
    public function test_el_resumen_del_rango_trae_turnos_asistidos_ausentes_y_tasa(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $integracion = $this->integracion($tenant);

        foreach (['2026-08-05 09:00', '2026-08-06 09:00', '2026-08-07 09:00'] as $cuando) {
            $this->turno($tenant, $cuando, 'Vino', attendance: 'attended', integracion: $integracion);
        }
        $this->turno($tenant, '2026-08-08 09:00', 'Faltó', attendance: 'no_show', integracion: $integracion);

        $resumen = $this->resumen($usuario, '2026-08-01', '2026-08-31');

        $this->assertSame(4, (int) $resumen['turnos']);
        $this->assertSame(3, (int) $resumen['asistidos']);
        $this->assertSame(1, (int) $resumen['ausentes']);
        $this->assertEqualsWithDelta(25.0, (float) $resumen['tasa_ausentismo'], 0.01,
            'La tasa de ausentismo no es ausentes sobre turnos marcados.');
    }

    /**
     * ⚠️ **La ambigüedad más cara del ticket.** El escenario tiene 4 marcados (3
     * asistidos, 1 ausente) y 2 sin marcar.
     *
     * - Sobre los marcados: 1/4 = **25%**.
     * - Sobre todos los del rango: 1/6 = **16,7%**.
     *
     * Se afirma la primera: los sin marcar son datos que no tenemos, no
     * asistencias. Con la segunda, la tasa **baja cuando el dueño marca menos** y
     * el número que sostiene el precio del producto mejora solo.
     */
    public function test_la_tasa_de_ausentismo_se_calcula_sobre_los_turnos_marcados(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $integracion = $this->integracion($tenant);

        foreach (['2026-08-05 09:00', '2026-08-06 09:00', '2026-08-07 09:00'] as $cuando) {
            $this->turno($tenant, $cuando, 'Vino', attendance: 'attended', integracion: $integracion);
        }
        $this->turno($tenant, '2026-08-08 09:00', 'Faltó', attendance: 'no_show', integracion: $integracion);
        $this->turno($tenant, '2026-08-09 09:00', 'Sin marcar 1', integracion: $integracion);
        $this->turno($tenant, '2026-08-10 09:00', 'Sin marcar 2', integracion: $integracion);

        $resumen = $this->resumen($usuario, '2026-08-01', '2026-08-31');

        $this->assertSame(6, (int) $resumen['turnos'],
            '"Cantidad de turnos" tiene que ser todos los del rango, marcados o no.');
        $this->assertEqualsWithDelta(25.0, (float) $resumen['tasa_ausentismo'], 0.01,
            'La tasa salió sobre todos los turnos del rango: los sin marcar la diluyen y baja sola.');
    }

    /**
     * ⚠️ **RNF-02.** "Del 1 al 31 de agosto" es agosto **del negocio**, no de UTC.
     *
     * Bogotá es UTC-5, así que:
     * - Un turno a las `2026-08-01 03:00 UTC` es el **31 de julio** allá: queda afuera.
     * - Uno a las `2026-09-01 02:00 UTC` es el **31 de agosto** allá: entra.
     *
     * Si el código compara fechas UTC, el resultado se **invierte** exactamente:
     * mismo `turnos`, pero el asistido y el ausente cambian de lugar.
     */
    public function test_el_rango_se_interpreta_en_la_zona_del_tenant_y_no_en_utc(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $integracion = $this->integracion($tenant);

        // 31 de julio, 22:00 en Bogotá. Fuera del rango.
        $this->turno($tenant, '2026-07-31 22:00', 'De julio', attendance: 'no_show', integracion: $integracion);
        // 31 de agosto, 21:00 en Bogotá. Dentro del rango.
        $this->turno($tenant, '2026-08-31 21:00', 'De agosto', attendance: 'attended', integracion: $integracion);

        $resumen = $this->resumen($usuario, '2026-08-01', '2026-08-31');

        $this->assertSame(1, (int) $resumen['turnos']);
        $this->assertSame(1, (int) $resumen['asistidos'],
            'El rango se recortó en UTC: entró el turno del 31 de julio del negocio.');
        $this->assertSame(0, (int) $resumen['ausentes'],
            'El rango se recortó en UTC: se contó como ausente un turno de julio.');
    }

    /** RNF-01 · La tasa de una PyME no puede contar los turnos de otra. */
    public function test_el_resumen_no_cuenta_turnos_de_otra_pyme(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));

        $mio = $this->tenant('a');
        $ajeno = $this->tenant('b');

        $this->assertNotSame((string) $mio->id, (string) $ajeno->id);

        $usuario = $this->usuario($mio);
        $this->turno($mio, '2026-08-05 09:00', 'Vino', attendance: 'attended');
        $this->turno($ajeno, '2026-08-05 09:00', 'Faltó en la otra', attendance: 'no_show');
        $this->turno($ajeno, '2026-08-06 09:00', 'Faltó en la otra', attendance: 'no_show');

        $resumen = $this->resumen($usuario, '2026-08-01', '2026-08-31');

        $this->assertSame(1, (int) $resumen['turnos'],
            'El resumen contó turnos de otra PyME.');
        $this->assertEqualsWithDelta(0.0, (float) $resumen['tasa_ausentismo'], 0.01,
            'La tasa de esta PyME incluyó ausencias de otra.');
    }

    /**
     * Un turno cancelado no es una ausencia: el cliente avisó y el horario se
     * liberó. Contarlo como ausente infla el KPI con casos que el producto
     * justamente resolvió bien.
     */
    public function test_un_turno_cancelado_no_cuenta_como_ausente(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $integracion = $this->integracion($tenant);

        $this->turno($tenant, '2026-08-05 09:00', 'Vino', attendance: 'attended', integracion: $integracion);
        $this->turno($tenant, '2026-08-06 09:00', 'Canceló', status: 'cancelled', integracion: $integracion);

        $resumen = $this->resumen($usuario, '2026-08-01', '2026-08-31');

        $this->assertSame(0, (int) $resumen['ausentes'],
            'Un turno cancelado se contó como ausencia.');
        $this->assertEqualsWithDelta(0.0, (float) $resumen['tasa_ausentismo'], 0.01);
    }

    // ------------------------- AC-17.4 · cruce con la respuesta al recordatorio

    /**
     * AC-17.4 · El criterio que US-17 declara **el más importante**: sin él se
     * sabe cuál es la tasa, pero no si el recordatorio la causó.
     */
    public function test_cada_turno_marcado_dice_si_el_cliente_habia_confirmado_el_recordatorio(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $id = $this->turno($tenant, '2026-08-05 09:00', 'Confirmó y vino',
            status: 'confirmed', attendance: 'attended');
        $this->recordatorioEnviado($tenant, $id);

        $marcados = $this->listaDeLaVista(
            $this->actingAs($usuario)->get(self::URL.'?desde=2026-08-01&hasta=2026-08-31')
                ->assertSuccessful(),
            'marcados',
        );

        $fila = $this->buscar($marcados, $id);

        $this->assertNotNull($fila, 'El turno marcado no apareció en el listado del rango.');
        $this->assertSame('confirmado', $fila['respuesta_recordatorio'],
            'No se puede saber si el cliente había confirmado el recordatorio.');
    }

    /**
     * El cruce que vale plata: **confirmó y no vino**. Es el caso que obliga a
     * que `attendance` sea columna aparte de `status` (T-036), y el único que
     * permite decir si el recordatorio sirvió o solo dio la sensación de servir.
     */
    public function test_el_que_confirmo_y_no_vino_queda_identificado(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $id = $this->turno($tenant, '2026-08-05 09:00', 'Confirmó y faltó',
            status: 'confirmed', attendance: 'no_show');
        $this->recordatorioEnviado($tenant, $id);

        $marcados = $this->listaDeLaVista(
            $this->actingAs($usuario)->get(self::URL.'?desde=2026-08-01&hasta=2026-08-31')
                ->assertSuccessful(),
            'marcados',
        );

        $fila = $this->buscar($marcados, $id);

        $this->assertNotNull($fila);
        $this->assertSame('no_show', $fila['attendance']);
        $this->assertSame('confirmado', $fila['respuesta_recordatorio'],
            'Se perdió que este cliente había confirmado: sin eso el ausentismo no se puede atribuir.');
    }

    /**
     * AC-17.4 · Tres respuestas distintas, no dos.
     *
     * ⚠️ **Acá está el recorte que tuve que hacer y no puedo inventar.** El
     * ticket pide cuatro valores —confirmó, canceló, re-agendó, no respondió— y
     * dos de ellos **no son alcanzables con lo que hay guardado**:
     *
     * - **`cancelado`:** cancelar desde el recordatorio deja el turno
     *   `cancelled` y libera el horario (T-037, AC-10.3). Ese turno nunca se
     *   marca, así que nunca es un "turno marcado" en el sentido de AC-17.4.
     * - **`re-agendado`:** T-037 deja el turno en `scheduled` al re-agendar, o
     *   sea **indistinguible de no haber respondido**. No hay columna que los
     *   separe.
     *
     * Lo que sí se afirma es la distinción que el dato permite y que la
     * atribución necesita: **"se le mandó el recordatorio y no contestó"** contra
     * **"nunca le llegó un recordatorio"**. Sin separarlas, un turno sin
     * recordatorio ensucia el grupo de control.
     */
    public function test_se_distingue_al_que_no_respondio_del_que_nunca_recibio_recordatorio(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'));

        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $integracion = $this->integracion($tenant);

        $mudo = $this->turno($tenant, '2026-08-05 09:00', 'No contestó',
            attendance: 'no_show', integracion: $integracion);
        $this->recordatorioEnviado($tenant, $mudo);

        $sinAviso = $this->turno($tenant, '2026-08-06 09:00', 'Nunca le avisamos',
            attendance: 'no_show', integracion: $integracion);

        $marcados = $this->listaDeLaVista(
            $this->actingAs($usuario)->get(self::URL.'?desde=2026-08-01&hasta=2026-08-31')
                ->assertSuccessful(),
            'marcados',
        );

        $this->assertSame('sin_respuesta', $this->buscar($marcados, $mudo)['respuesta_recordatorio'] ?? null,
            'Un cliente que recibió el recordatorio y no contestó no queda identificado.');
        $this->assertSame('sin_recordatorio', $this->buscar($marcados, $sinAviso)['respuesta_recordatorio'] ?? null,
            'Un turno sin recordatorio se cuenta como "no respondió" y ensucia el grupo de control.');
    }

    // ------------------------------------------------- El índice de T-009

    /**
     * Último criterio: *"la consulta de pendientes usa el índice de T-009 y no
     * escanea la tabla"*. Es medible, no una opinión — se mide con `EXPLAIN`
     * sobre la consulta que la pantalla ejecuta de verdad, con datos sembrados.
     *
     * Se siembran 60 turnos del tenant contra 740 de otro: con la tabla casi
     * vacía MySQL escanea igual, y el test no probaría nada. La proporción está
     * elegida para que `ref` sobre el índice sea claramente más barato que el
     * scan; verificado a mano contra MySQL 8.4 antes de escribir el test.
     */
    public function test_la_consulta_de_pendientes_usa_el_indice_de_t009_y_no_escanea_la_tabla(): void
    {
        $tenant = $this->tenant('a');
        $ruidoso = $this->tenant('b');
        $usuario = $this->usuario($tenant);

        $this->sembrar($tenant, 60);
        $this->sembrar($ruidoso, 740);

        $consultas = [];
        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = $consulta;
        });

        $this->actingAs($usuario)->get(self::URL)->assertSuccessful();

        $planes = [];

        foreach ($consultas as $consulta) {
            if (! str_contains(strtolower($consulta->sql), 'from `bookings`')) {
                continue;
            }

            $planes[] = [
                'sql' => $consulta->sql,
                'plan' => DB::selectOne('EXPLAIN '.$consulta->sql, $this->bindings($consulta->bindings)),
            ];
        }

        $this->assertNotEmpty($planes, 'La pantalla de pendientes no consultó `bookings`.');

        foreach ($planes as $p) {
            $this->assertNotSame('ALL', $p['plan']->type,
                "Esta consulta escanea toda la tabla `bookings`:\n{$p['sql']}");
        }

        $usados = array_column(array_column($planes, 'plan'), 'key');

        $this->assertContains('bookings_tenant_id_start_time_status_index', $usados,
            'Ninguna consulta de la pantalla usó el índice (tenant_id, start_time, status) de T-009. '
            .'Índices elegidos: '.implode(', ', array_map(fn ($k) => $k ?? 'ninguno', $usados)));
    }

    /** Turnos pasados sin marcar, en bloque, para que el optimizador tenga qué elegir. */
    private function sembrar(Tenant $tenant, int $cantidad): void
    {
        $integracion = $this->integracion($tenant);
        $base = CarbonImmutable::parse(self::AHORA, 'UTC')->subDays(2);

        foreach (array_chunk(range(1, $cantidad), 200) as $lote) {
            DB::table('bookings')->insert(array_map(fn (int $n) => [
                'tenant_id' => $tenant->id,
                'integration_id' => $integracion,
                'external_event_id' => 'evt-'.$tenant->slug.'-'.$n,
                'client_name' => 'Cliente '.$n,
                'client_phone' => '573001112233',
                'service_name' => 'corte',
                'start_time' => $base->subHours($n)->format('Y-m-d H:i:s'),
                'end_time' => $base->subHours($n)->addMinutes(30)->format('Y-m-d H:i:s'),
                'status' => 'scheduled',
                'attendance' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ], $lote));
        }
    }

    /**
     * Los bindings tal como los recibe el driver: `EXPLAIN` no acepta un
     * `DateTimeInterface` ni un enum como parámetro.
     *
     * @param  array<int|string,mixed>  $bindings
     * @return array<int|string,mixed>
     */
    private function bindings(array $bindings): array
    {
        return array_map(function ($valor) {
            if ($valor instanceof \DateTimeInterface) {
                return $valor->format('Y-m-d H:i:s');
            }

            if ($valor instanceof \BackedEnum) {
                return $valor->value;
            }

            return is_bool($valor) ? (int) $valor : $valor;
        }, $bindings);
    }
}
