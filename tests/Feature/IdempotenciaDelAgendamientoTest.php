<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-030 · AC-22.3 · Un reintento no crea un segundo evento ni una segunda fila.
 *
 * El ticket lo dice sin recorte: *«La idempotencia hacia Google (AC-22.3) y el
 * orden persistir → confirmar (AC-22.2) no se recortan.»* Hoy no existe: cada
 * `POST` a Google es un evento nuevo, y dos intentos del mismo agendamiento
 * dejan **dos** turnos en el calendario de la PyME sobre el mismo horario, uno
 * de ellos sin fila que lo reclame.
 *
 * ## Por qué el doble de Google es *con estado* y no un `Http::sequence()`
 *
 * La tentación es programar la secuencia `[200, 409]`: el primer intento crea,
 * el segundo choca. **Eso pasaría en verde sin idempotencia ninguna.** El 409
 * llegaría porque el test lo decidió, no porque le hayamos mandado a Google una
 * clave repetida — que es justamente lo que hay que probar.
 *
 * Por eso el doble se comporta como Google: **lleva la cuenta de los `id` que ya
 * usó** y rechaza con `409 duplicate` el que se repite, tal como documenta
 * `events.insert`. Con la implementación de hoy —que no manda `id`— Google
 * inventa uno distinto cada vez y el segundo intento crea un evento nuevo: el
 * test se pone rojo, que es lo correcto.
 *
 * La guarda que reemplaza al *«afirmá que el doble del fallo se consumió»* es
 * `assertSame(2, ...POSTs)` + `assertGreaterThan(0, ...409s)`: si el 409 nunca
 * ocurrió, es que nunca mandamos una clave repetida.
 *
 * ## El reintento se ejerce sobre `Reserva`, no sobre `ProcessMessageJob`
 *
 * ⚠️ Y no es una comodidad: **a nivel del job, el reintento hoy no llega nunca
 * hasta Google**. `ProcessMessageJob::marcarProcesado()` corre al final, así que
 * un rate limit de Meta en la confirmación sí hace reintentar el job — pero el
 * `id` sellado del botón que el cliente tocó ya quedó viejo (`SlotElegido` y
 * `ReservaConfirmada` subieron `state_version`), el intérprete lo clasifica como
 * caduco y el flujo contesta el paso actual sin volver a agendar.
 *
 * Ese sello **no es la garantía que pide AC-22.3**: protege contra un cliente
 * que toca un botón viejo, no contra un reintento de la cola, y depende de que
 * la transición de estado haya llegado a aplicarse. La idempotencia tiene que
 * vivir en la operación que le escribe a Google, que es lo que se prueba acá.
 */
class IdempotenciaDelAgendamientoTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Bogota';

    private Tenant $tenant;

    private Integration $google;

    /**
     * El calendario del doble: `id de evento => true`.
     *
     * Es el estado que hace que el doble se parezca a Google. Sin él, el test
     * decidiría cuándo hay conflicto en vez de descubrirlo.
     *
     * @var array<string,bool>
     */
    private array $eventosCreados = [];

    /** Cuántos `id` inventó el doble porque el cliente no mandó ninguno. */
    private int $idsInventados = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC'));

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $this->google = Integration::create([
            'tenant_id' => $this->tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio@peluqueria.com', 'access_token' => 'ya29.sur',
            'refresh_token' => '1//r', 'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // -------------------------------------------------------------- el doble

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: un segundo `fake()` no reemplaza al
     * primero y el doble del error nunca se usa. Ya pasó tres veces en T-026.
     */
    private function fakeDeGoogle(): void
    {
        Http::fake([
            // `Str::start($url, '*')` no agrega comodín al final: este patrón
            // matchea el `POST` de creación y **no** el `DELETE` ni el listado.
            'www.googleapis.com/calendar/v3/calendars/primary/events' => function (Request $request) {
                $id = $request->data()['id'] ?? null;

                if ($id === null) {
                    // Google inventa el `id` cuando el cliente no lo fija: cada
                    // llamada crea un evento distinto. Es el comportamiento de
                    // hoy, y es exactamente lo que rompe AC-22.3.
                    $id = 'evt_google_'.(++$this->idsInventados);
                }

                if (isset($this->eventosCreados[$id])) {
                    return Http::response([
                        'error' => [
                            'code' => 409,
                            'message' => 'The requested identifier already exists.',
                            'errors' => [['reason' => 'duplicate']],
                        ],
                    ], 409);
                }

                $this->eventosCreados[$id] = true;

                return Http::response([
                    'id' => $id,
                    'status' => 'confirmed',
                    'extendedProperties' => $request->data()['extendedProperties'] ?? [],
                ]);
            },

            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response([], 204),
        ]);
    }

    // ------------------------------------------------------------- utilidades

    private function conversacion(string $nombre = 'María', string $telefono = '5493764278402'): Conversation
    {
        return TenantContext::runAs($this->tenant->id, fn () => Conversation::create([
            'tenant_id' => $this->tenant->id,
            'user_phone' => $telefono,
            'current_state' => 'SLOT_SELECTED',
            'state_version' => 3,
            'context_data' => ['nombre' => $nombre, 'servicio' => 'Corte de pelo'],
            'last_interaction_at' => now(),
        ]));
    }

    private function config(): BusinessSetting
    {
        // `Tenant::created` ya sembró la configuración por defecto (T-014).
        return BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();
    }

    /**
     * Agenda como lo hace `ProcessMessageJob`: dentro del `runAs()` del tenant.
     *
     * Es el mismo objeto `Reserva` en las dos llamadas, igual que en un
     * reintento sería una instancia nueva; se resuelve del contenedor cada vez
     * para no arrastrar estado entre intentos.
     */
    private function agendar(Conversation $conversacion, CarbonImmutable $inicio): ?Booking
    {
        return TenantContext::runAs($this->tenant->id, fn () => app(\App\Conversacion\Reserva::class)->agendar(
            $conversacion,
            $this->tenant,
            $this->config(),
            $this->google,
            $inicio,
        ));
    }

    /** Los cuerpos de todos los `POST` de creación de evento, en orden. */
    private function creacionesEnviadas(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if ($req->method() === 'POST' && str_ends_with($req->url(), '/events')) {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    private function conflictosRecibidos(): int
    {
        $n = 0;

        foreach (Http::recorded() as [$req, $res]) {
            if ($res->status() === 409) {
                $n++;
            }
        }

        return $n;
    }

    private function inicio(string $hora = '2026-08-21 14:00:00'): CarbonImmutable
    {
        return CarbonImmutable::parse($hora, 'UTC');
    }

    // ------------------------------------------- la clave hacia Google existe

    /**
     * AC-22.3 · El evento se crea con una clave que **nosotros** elegimos.
     *
     * Google no ofrece otro mecanismo de idempotencia en `events.insert`: o el
     * cliente fija el `id` del evento —y un segundo `insert` con el mismo `id`
     * devuelve `409 duplicate`—, o cada llamada crea un evento nuevo. Por eso el
     * criterio *«clave de idempotencia determinística hacia Google»* del alcance
     * se observa acá: en el `id` que viaja en el cuerpo del `POST`.
     */
    public function test_la_creacion_del_evento_lleva_una_clave_de_idempotencia(): void
    {
        $this->fakeDeGoogle();

        $this->agendar($this->conversacion(), $this->inicio());

        $creaciones = $this->creacionesEnviadas();

        $this->assertCount(1, $creaciones, 'No se creó el evento en Google.');
        $this->assertArrayHasKey('id', $creaciones[0],
            'El POST a Google no lleva `id`: sin clave propia, Google inventa uno distinto '
            .'en cada intento y el reintento crea un segundo turno sobre el mismo horario.');
        $this->assertNotSame('', (string) $creaciones[0]['id']);
    }

    /**
     * AC-22.3 · **Determinística**: el mismo agendamiento produce la misma clave.
     *
     * Es la propiedad entera. Una clave aleatoria —un `uuid()` por intento—
     * satisface «lleva un id» y no evita nada: el reintento mandaría otro y
     * Google crearía el segundo evento igual.
     *
     * ⚠️ **De qué se deriva la clave no lo fija el ticket.** El test solo afirma
     * que dos intentos del *mismo* agendamiento coinciden y que dos
     * agendamientos *distintos* no; la fórmula queda a criterio de quien
     * implemente. Lo que sí implica es que no puede depender de nada que cambie
     * entre intentos: ni la hora del reloj, ni `state_version`, ni un aleatorio.
     */
    public function test_la_clave_es_la_misma_en_los_dos_intentos_del_mismo_turno(): void
    {
        $this->fakeDeGoogle();

        $conversacion = $this->conversacion();
        $inicio = $this->inicio();

        $this->agendar($conversacion, $inicio);

        // Un rato después: un reintento no ocurre en el mismo instante, y una
        // clave que dependa del reloj se delataría acá.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(20));

        $this->agendar($conversacion->fresh(), $inicio);

        $creaciones = $this->creacionesEnviadas();

        $this->assertCount(2, $creaciones, 'El segundo intento no llegó a Google.');
        $this->assertSame(
            $creaciones[0]['id'] ?? null,
            $creaciones[1]['id'] ?? '(sin id)',
            'El reintento mandó otra clave: Google va a crear un segundo evento.'
        );
    }

    /**
     * AC-22.3 · Dos turnos distintos **no** comparten la clave.
     *
     * El reverso del test anterior, y no es simetría por prolijidad: una clave
     * que colapse dos agendamientos distintos —por ejemplo, una que solo mire el
     * `tenant_id`— haría que el segundo cliente del día reciba un `409` y se
     * quede sin turno. El modo de falla sería *idempotencia de más*.
     */
    public function test_dos_turnos_distintos_no_comparten_la_clave(): void
    {
        $this->fakeDeGoogle();

        $this->agendar($this->conversacion('María', '5493764278402'), $this->inicio('2026-08-21 14:00:00'));
        $this->agendar($this->conversacion('Juan', '5493764278403'), $this->inicio('2026-08-21 15:00:00'));

        $creaciones = $this->creacionesEnviadas();

        $this->assertCount(2, $creaciones);
        $this->assertNotNull($creaciones[0]['id'] ?? null, 'El primer POST no lleva clave.');
        $this->assertNotNull($creaciones[1]['id'] ?? null, 'El segundo POST no lleva clave.');
        $this->assertNotSame($creaciones[0]['id'], $creaciones[1]['id'],
            'Dos turnos distintos comparten la clave: el segundo cliente se queda sin turno.');

        $this->assertSame(2, DB::table('bookings')->count());
    }

    /**
     * La clave tiene que ser **aceptable para Google**, no solo determinística.
     *
     * `events.insert` exige que el `id` esté en base32hex —caracteres `0-9` y
     * `a-v`— y mida entre 5 y 1024. Un hash hexadecimal crudo lo cumple; un
     * `uuid()` con guiones o un base64 con mayúsculas **no**, y Google contesta
     * `400 invalid` en producción sobre un camino que en la suite nunca se
     * ejerce porque el doble acepta cualquier cosa.
     *
     * ⚠️ Es la única afirmación del archivo que mira la *forma* y no el
     * comportamiento. Está acá a propósito: es una regla del tercero que ningún
     * test de comportamiento puede descubrir con un doble.
     */
    public function test_la_clave_respeta_el_formato_que_google_acepta(): void
    {
        $this->fakeDeGoogle();

        $this->agendar($this->conversacion(), $this->inicio());

        $id = (string) ($this->creacionesEnviadas()[0]['id'] ?? '');

        $this->assertMatchesRegularExpression('/^[0-9a-v]{5,1024}$/', $id,
            'Google rechaza este `id`: `events.insert` solo acepta base32hex (0-9, a-v) '
            .'de 5 a 1024 caracteres.');
    }

    // ------------------------------------------------- el reintento converge

    /**
     * AC-22.3 · **El reintento no crea un segundo evento ni una segunda fila.**
     *
     * Es el criterio textual. El doble de Google rechaza con `409 duplicate` la
     * clave repetida —como el Google real—, así que el segundo evento solo
     * aparece si le mandamos una clave distinta.
     *
     * Las dos guardas del final son las que impiden que este test pase por la
     * razón equivocada: si nunca hubo un `409`, es que nunca se repitió la clave
     * y la unicidad de la fila la estaría dando otra cosa.
     */
    public function test_un_reintento_no_crea_un_segundo_evento_ni_una_segunda_fila(): void
    {
        $this->fakeDeGoogle();

        $conversacion = $this->conversacion();
        $inicio = $this->inicio();

        $this->agendar($conversacion, $inicio);
        $this->agendar($conversacion->fresh(), $inicio);

        $this->assertCount(1, $this->eventosCreados,
            'El reintento creó un segundo evento en el calendario de la PyME: el horario '
            .'queda ocupado dos veces por el mismo turno.');

        $this->assertSame(1, DB::table('bookings')->count(),
            'El reintento creó una segunda fila en `bookings`: dos recordatorios para el mismo turno.');

        // Guardas contra el verde falso.
        $this->assertCount(2, $this->creacionesEnviadas(),
            'El segundo intento ni siquiera llegó a Google: el test no probó la idempotencia.');
        $this->assertGreaterThan(0, $this->conflictosRecibidos(),
            'Google nunca contestó 409: la clave repetida no se mandó, así que la fila única '
            .'la está dando otra cosa y no la idempotencia.');
    }

    /**
     * AC-22.2 + AC-22.3 · El reintento **recupera el turno**, no lo da por fallido.
     *
     * ⚠️ **Interpretación mía, y la explico porque el criterio no la dice.**
     * AC-22.3 solo pide que no se dupliquen evento y fila. Pero si el reintento
     * devolviera «no se pudo agendar», `ProcessMessageJob::agendar()` llamaría a
     * `Fallback::manejar()` con ese `external_event_id` como huérfano — y
     * `liberarEvento()` **borraría del calendario un turno que sí existe y que
     * la PyME ya vio**. El cliente, además, recibiría el mensaje de cortesía por
     * un turno que quedó bien agendado.
     *
     * O sea: «no duplicar» sin «converger» convierte el reintento en una
     * cancelación silenciosa. Por eso se afirma que la segunda llamada devuelve
     * **el mismo** turno.
     */
    public function test_un_reintento_devuelve_el_turno_que_ya_existia(): void
    {
        $this->fakeDeGoogle();

        $conversacion = $this->conversacion();
        $inicio = $this->inicio();

        $primero = $this->agendar($conversacion, $inicio);
        $segundo = $this->agendar($conversacion->fresh(), $inicio);

        $this->assertNotNull($primero, 'El primer intento no agendó nada.');
        $this->assertNotNull($segundo,
            'El reintento devolvió «no se pudo agendar» sobre un turno que sí existe: el '
            .'fallback le borraría a la PyME un evento real y le pediría disculpas al cliente '
            .'por un turno que quedó bien.');

        $this->assertSame($primero->id, $segundo->id, 'El reintento no convergió al mismo turno.');
        $this->assertSame($primero->external_event_id, $segundo->external_event_id);

        // `tenant_id` es UUID: se compara como string. Un `(int)` sobre un UUID
        // devuelve 1 para todos y la afirmación pasaría siempre.
        $this->assertSame($this->tenant->id, (string) $segundo->tenant_id);
    }

    /**
     * La base no depende de que el código se acuerde: **la fila única lo impide**.
     *
     * Un `SELECT` previo no es una garantía —dos workers pueden mirar a la vez y
     * los dos ver «no está»—. El candado real es el índice, como en
     * `notification_logs` (T-009) y en `processed_messages` (T-011). Sin él, la
     * idempotencia es una comprobación en PHP con una ventana de carrera abierta
     * justo en el momento que el ticket existe para cerrar.
     */
    public function test_la_base_impide_dos_filas_para_el_mismo_evento(): void
    {
        $this->fakeDeGoogle();

        $conversacion = $this->conversacion();
        $primero = $this->agendar($conversacion, $this->inicio());
        $this->assertNotNull($primero);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Se saltea el código a propósito: se escribe directo contra la tabla.
        DB::table('bookings')->insert([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversacion->id,
            'integration_id' => $this->google->id,
            'external_event_id' => $primero->external_event_id,
            'client_name' => 'María',
            'client_phone' => '5493764278402',
            'service_name' => 'Corte de pelo',
            'start_time' => '2026-08-21 14:00:00',
            'end_time' => '2026-08-21 14:30:00',
            'status' => Booking::ESTADO_AGENDADO,
            'attendance' => Booking::ASISTENCIA_PENDIENTE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
