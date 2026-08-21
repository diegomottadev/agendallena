<?php

namespace Tests\Feature;

use App\Conversacion\Interactivo\IdSellado;
use App\Conversacion\ListaDeHorarios;
use App\Jobs\ProcessMessageJob;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\HoraLocal;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-031 · Suite transversal de zonas horarias (US-07).
 *
 * ## Por qué esta suite y no una feature
 *
 * US-07 no debería ser una historia: los husos son una **restricción
 * transversal**. Como ítem de backlog invitan a que alguien los marque "hechos"
 * en un sprint y después se violen en silencio en cada historia nueva. El
 * entregable de este ticket son estos tests, no código de producto.
 *
 * Es además **el error más caro del producto porque es silencioso**: nadie lo
 * detecta hasta que un cliente llega tres horas tarde. No hay excepción, no hay
 * log, no hay 500. Sólo un turno mal.
 *
 * ## Qué se verifica acá y no en `ZonaHorariaTest`
 *
 * `ZonaHorariaTest` prueba las piezas —el cast, `HoraLocal`, la config—. Esta
 * suite recorre el **flujo real de reserva** de punta a punta, que es donde las
 * piezas se conectan y donde una conversión de más o de menos se materializa en
 * una fila de `bookings` y en un evento de Google. Un objeto suelto puede estar
 * bien y el flujo estar mal.
 *
 * ## Los tres husos y el cambio estacional
 *
 * - `America/Argentina/Buenos_Aires` — UTC−3 todo el año, sin cambio de hora.
 * - `America/Bogota` — UTC−5 todo el año.
 * - `America/Santiago` — **UTC−4 en invierno y UTC−3 en verano**. Es el caso que
 *   sólo falla unas semanas al año y el que ninguna suite escrita sobre una zona
 *   fija puede atrapar.
 */
class ZonasHorariasTransversalTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '5493764278402';

    /**
     * Piso de la base de husos de PHP, la que usa Carbon y por lo tanto todo el
     * producto. Ver `test_la_version_de_tzdata_de_php_no_es_anterior_a_la_fijada`.
     */
    private const TZDATA_PHP_MINIMA = '2026.3';

    /** Piso de la base de husos de ICU (extensión `intl`). Ver el mismo test. */
    private const TZDATA_ICU_MINIMA = '2024b';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------- montaje

    /**
     * Un tenant en `$tz` que atiende **una sola hora por día**, la que se le pase.
     *
     * Que el negocio abra 15:00–16:00 con turnos de 60 minutos y sin buffer deja
     * exactamente **un candidato por día, a las 15:00 locales**. La lista que
     * recibe el cliente queda así libre de ruido: cada fila es "las 15:00 de un
     * día distinto", y cualquier corrimiento de una hora salta a la vista en vez
     * de esconderse entre veinte horarios.
     *
     * @param  string  $ahoraLocal  El "ahora" del test, en hora **local** del tenant.
     */
    private function montar(string $tz, string $ahoraLocal, string $apertura = '15:00', string $cierre = '16:00'): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($ahoraLocal, $tz));

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto-'.uniqid(),
            'status' => 'active', 'timezone' => $tz,
        ]);

        Integration::create([
            'tenant_id' => $this->tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => self::PHONE_NUMBER_ID, 'access_token' => 'meta',
            'settings' => ['verify_token' => 'tok'], 'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $this->tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio@peluqueria.com', 'access_token' => 'ya29',
            'refresh_token' => '1//r', 'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        $rango = [[$apertura, $cierre]];

        BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->first()
            ->update([
                'business_hours' => [
                    'mon' => $rango, 'tue' => $rango, 'wed' => $rango, 'thu' => $rango,
                    'fri' => $rango, 'sat' => $rango, 'sun' => $rango,
                ],
                'slot_duration_minutes' => 60,
                'buffer_minutes' => 0,
                'search_window_days' => 7,
            ]);
    }

    /**
     * Declara los dobles de HTTP. **Se llama una sola vez por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: llamarlo dos veces no reemplaza el
     * primero, y sigue ganando el que coincida primero. Un test que re-fakea a
     * mitad de camino pasaría por la razón equivocada.
     */
    private function fakes(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['id' => 'evt_google_123']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);
    }

    // ------------------------------------------------------------- el flujo

    /** @return array<string,mixed> */
    private function texto(string $cuerpo): array
    {
        return $this->payload(['from' => self::TELEFONO, 'id' => 'wamid.'.uniqid(),
            'type' => 'text', 'text' => ['body' => $cuerpo]]);
    }

    /** @return array<string,mixed> */
    private function toca(string $id): array
    {
        return $this->payload(['from' => self::TELEFONO, 'id' => 'wamid.'.uniqid(),
            'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => 'x']]]);
    }

    /**
     * @param  array<string,mixed>  $mensaje
     * @return array<string,mixed>
     */
    private function payload(array $mensaje): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [$mensaje]],
        ]]]]];
    }

    /**
     * Los cuerpos que se le mandaron a Meta, en orden.
     *
     * @return array<int,array<string,mixed>>
     */
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

    /** El cuerpo del `POST /events` que se le mandó a Google. */
    private function aGoogleEventos(): ?array
    {
        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), '/events') && $req->method() === 'POST') {
                return $req->data();
            }
        }

        return null;
    }

    /**
     * Saluda, toca «Reservar», da el nombre y devuelve las filas de la lista.
     *
     * @return array<int,array{id:string,titulo:string,descripcion:string,horario:CarbonImmutable}>
     */
    private function horariosOfrecidos(string $nombre = 'María'): array
    {
        (new ProcessMessageJob($this->texto('Hola')))->handle();

        $reservar = $this->aMeta()[0]['interactive']['action']['buttons'][0]['reply']['id'];
        (new ProcessMessageJob($this->toca($reservar)))->handle();
        (new ProcessMessageJob($this->texto($nombre)))->handle();

        $lista = null;

        foreach (array_reverse($this->aMeta()) as $m) {
            if (($m['interactive']['type'] ?? null) === 'list') {
                $lista = $m;
                break;
            }
        }

        $this->assertNotNull($lista, 'Nunca se ofrecio la lista de horarios.');

        $filas = [];

        foreach ($lista['interactive']['action']['sections'][0]['rows'] as $fila) {
            $horario = ListaDeHorarios::horarioDe(IdSellado::leer($fila['id'])->accion);

            if ($horario === null) {
                continue;   // fila de «ver más»
            }

            $filas[] = [
                'id' => $fila['id'],
                'titulo' => $fila['title'],
                'descripcion' => $fila['description'] ?? '',
                'horario' => $horario,
            ];
        }

        $this->assertNotEmpty($filas, 'La lista no trajo ningun horario.');

        return $filas;
    }

    /**
     * Elige el horario del día `$fechaLocal` y devuelve el turno persistido.
     *
     * @param  array<int,array{id:string,horario:CarbonImmutable}>  $filas
     */
    private function elegir(array $filas, string $fechaLocal): Booking
    {
        foreach ($filas as $fila) {
            if ($fila['horario']->setTimezone($this->tenant->timezone)->toDateString() === $fechaLocal) {
                (new ProcessMessageJob($this->toca($fila['id'])))->handle();

                $booking = TenantContext::runAs($this->tenant->id, fn () => Booking::first());

                $this->assertNotNull($booking, "Se eligio {$fechaLocal} y no quedo el turno persistido.");

                return $booking;
            }
        }

        $this->fail("La lista no ofrecio ningun horario del {$fechaLocal}.");
    }

    /** El texto crudo de la columna, sin pasar por el cast. */
    private function startTimeCrudo(): string
    {
        return (string) DB::table('bookings')->value('start_time');
    }

    // ---------------------------------------------------------- AC-07.1

    /**
     * AC-07.1 · Buenos Aires, 15:00 → la base guarda 18:00 UTC y Google ve 15:00.
     *
     * Argentina es UTC−3 **todo el año**: no tiene horario de verano desde 2009.
     * Es la línea de base contra la que se leen los otros dos casos.
     */
    public function test_ac_07_1_buenos_aires_a_las_15_guarda_18_utc_y_el_evento_muestra_15(): void
    {
        $this->fakes();
        $this->montar('America/Argentina/Buenos_Aires', '2026-08-24 08:00');

        $booking = $this->elegir($this->horariosOfrecidos(), '2026-08-24');

        // 1 · La columna, sin cast de por medio: lo que hay escrito es UTC.
        $this->assertSame('2026-08-24 18:00:00', $this->startTimeCrudo(),
            'Se guardo la hora local en vez del instante UTC: la base dejo de estar en UTC (RNF-02).');

        // 2 · Y el turno vuelve a las 15:00 cuando se lo mira desde el tenant.
        $this->assertSame('15:00', HoraLocal::hora($booking->start_time, $this->tenant));

        // 3 · El evento que viaja a Google muestra 15:00 locales, con su offset.
        $evento = $this->aGoogleEventos();
        $this->assertSame('America/Argentina/Buenos_Aires', $evento['start']['timeZone']);
        $this->assertSame('2026-08-24T15:00:00-03:00', $evento['start']['dateTime']);

        // 4 · Y las dos representaciones son **el mismo instante**. Esta es la
        //     comparación que atrapa el corrimiento: una fila en UTC y un evento
        //     en hora local pueden verse los dos "bien" y describir momentos
        //     distintos si alguien sumó o restó horas a mano.
        $this->assertMismoInstante($evento['start']['dateTime'], $booking->start_time);
    }

    // ---------------------------------------------------------- AC-07.2

    /** AC-07.2 · Bogotá, 15:00 → la base guarda 20:00 UTC. Colombia es UTC−5 fijo. */
    public function test_ac_07_2_bogota_a_las_15_guarda_20_utc(): void
    {
        $this->fakes();
        $this->montar('America/Bogota', '2026-08-24 08:00');

        $booking = $this->elegir($this->horariosOfrecidos(), '2026-08-24');

        $this->assertSame('2026-08-24 20:00:00', $this->startTimeCrudo(),
            'Bogota es UTC-5: las 15:00 locales son las 20:00 UTC.');

        $this->assertSame('15:00', HoraLocal::hora($booking->start_time, $this->tenant));

        $evento = $this->aGoogleEventos();
        $this->assertSame('2026-08-24T15:00:00-05:00', $evento['start']['dateTime']);
        $this->assertMismoInstante($evento['start']['dateTime'], $booking->start_time);
    }

    // ---------------------------------------------------------- AC-07.3

    /**
     * AC-07.3 · Santiago, turno **posterior al cambio de horario estacional**.
     *
     * ## Por qué este es el caso que importa
     *
     * Chile pasa a horario de verano el **domingo 6 de septiembre de 2026** a la
     * medianoche: `America/Santiago` va de UTC−4 a UTC−3. El test se para el
     * jueves 3 —todavía UTC−4— y agenda para el martes 8 —ya UTC−3—.
     *
     * Cualquier código que resuelva el offset **una sola vez** (al consultar
     * disponibilidad, al armar la lista) y lo reutilice para convertir el turno
     * guarda 19:00 UTC en vez de 18:00. El cliente ve las 15:00 en el chat, el
     * dueño ve las 16:00 en su calendario, y nadie se entera hasta que alguien
     * llega una hora tarde. Es exactamente el bug que sólo aparece unas semanas
     * al año y que ninguna suite escrita sobre Buenos Aires puede atrapar.
     *
     * Como remate, la medianoche del 6 de septiembre **no existe** en Santiago
     * —el reloj salta de 23:59 a 01:00—, y `GeneradorDeHorarios::diaDelNegocio()`
     * construye justo esa medianoche para saber qué día es. Que la grilla del
     * domingo salga bien es parte de lo que se verifica.
     */
    public function test_ac_07_3_santiago_despues_del_cambio_de_hora_no_se_corre_una_hora(): void
    {
        $this->fakes();
        $this->montar('America/Santiago', '2026-09-03 08:00');

        // Martes 8: ya en horario de verano (UTC-3).
        $booking = $this->elegir($this->horariosOfrecidos(), '2026-09-08');

        $this->assertSame('2026-09-08 18:00:00', $this->startTimeCrudo(),
            'Se guardaron las 19:00 UTC: se reuso el offset de invierno (UTC-4) para un turno de verano (UTC-3).');

        // La hora mostrada y la del calendario coinciden, sin desplazamiento.
        $this->assertSame('15:00', HoraLocal::hora($booking->start_time, $this->tenant));

        $evento = $this->aGoogleEventos();
        $this->assertSame('2026-09-08T15:00:00-03:00', $evento['start']['dateTime'],
            'El evento no salio con el offset de verano de Chile.');
        $this->assertMismoInstante($evento['start']['dateTime'], $booking->start_time);

        // Y la confirmación que lee el cliente dice la misma hora que las otras dos.
        $this->assertStringContainsString('15:00', $this->ultimoTexto()['text']['body']);
    }

    /**
     * AC-07.3 · El control: el mismo tenant, un turno **anterior** al cambio.
     *
     * Es el par del test de arriba y existe para que ninguno de los dos pueda
     * pasar por casualidad. Las mismas 15:00 de Santiago valen **19:00 UTC el 3
     * de septiembre y 18:00 UTC el 8**. Un test solo, del lado que sea, se
     * satisface con cualquier offset fijo que dé la casualidad de coincidir
     * —UTC−3 es también el de Buenos Aires—; los dos juntos, no.
     */
    public function test_ac_07_3_santiago_antes_del_cambio_de_hora_usa_el_offset_de_invierno(): void
    {
        $this->fakes();
        $this->montar('America/Santiago', '2026-09-03 08:00');

        // Jueves 3: todavía horario de invierno (UTC-4).
        $booking = $this->elegir($this->horariosOfrecidos(), '2026-09-03');

        $this->assertSame('2026-09-03 19:00:00', $this->startTimeCrudo(),
            'Se guardaron las 18:00 UTC: se aplico el offset de verano (UTC-3) a un turno de invierno (UTC-4).');

        $this->assertSame('15:00', HoraLocal::hora($booking->start_time, $this->tenant));

        $evento = $this->aGoogleEventos();
        $this->assertSame('2026-09-03T15:00:00-04:00', $evento['start']['dateTime'],
            'El evento no salio con el offset de invierno de Chile.');
        $this->assertMismoInstante($evento['start']['dateTime'], $booking->start_time);
    }

    /**
     * AC-07.3 · Los dos lados del cambio de hora conviven en la misma lista.
     *
     * La ventana de búsqueda del 3 al 9 de septiembre cruza la transición: los
     * primeros días son UTC−4 y los últimos UTC−3. **Todos tienen que mostrarse
     * como las 15:00**, aunque su instante UTC difiera en una hora entre sí.
     *
     * Un cálculo con offset fijo pasaría el test de un solo día y fallaría acá:
     * mostraría 15:00 de un lado y 14:00 o 16:00 del otro.
     */
    public function test_ac_07_3_la_lista_muestra_las_15_a_los_dos_lados_del_cambio_de_hora(): void
    {
        $this->fakes();
        $this->montar('America/Santiago', '2026-09-03 08:00');

        $filas = $this->horariosOfrecidos();

        $utcPorDia = [];

        foreach ($filas as $fila) {
            $local = $fila['horario']->setTimezone('America/Santiago');

            $this->assertSame('15:00 hs', $fila['titulo'],
                "La fila del {$local->toDateString()} se muestra a las {$fila['titulo']} en vez de las 15:00.");

            $utcPorDia[$local->toDateString()] = $fila['horario']->utc()->format('H:i');
        }

        // Antes de la transición (UTC-4) y después (UTC-3): el mismo "15:00"
        // local corresponde a instantes UTC distintos, y eso es lo correcto.
        $this->assertSame('19:00', $utcPorDia['2026-09-03'] ?? null,
            'El 3 de septiembre Chile todavia esta en UTC-4.');
        $this->assertSame('18:00', $utcPorDia['2026-09-08'] ?? null,
            'El 8 de septiembre Chile ya esta en UTC-3.');

        // El domingo 6 es el día de la transición: su medianoche local no existe.
        // Que la grilla lo genere igual prueba que `diaDelNegocio()` no se rompe
        // construyendo una hora inexistente.
        $this->assertArrayHasKey('2026-09-06', $utcPorDia,
            'El dia del cambio de hora desaparecio de la grilla: la medianoche inexistente rompio el calculo del dia.');
    }

    // ---------------------------------------------------------- AC-07.4

    /**
     * AC-07.4 · Buenos Aires: ningún texto al cliente lleva la hora UTC.
     */
    public function test_ac_07_4_ningun_texto_al_cliente_lleva_hora_utc_en_buenos_aires(): void
    {
        $this->fakes();
        $this->montar('America/Argentina/Buenos_Aires', '2026-08-24 08:00');
        $this->elegir($this->horariosOfrecidos(), '2026-08-24');

        $this->assertTextosEnHoraLocal(local: '15:00', utc: '18:00');
    }

    /** AC-07.4 · Bogotá: ídem, con el desfase de cinco horas. */
    public function test_ac_07_4_ningun_texto_al_cliente_lleva_hora_utc_en_bogota(): void
    {
        $this->fakes();
        $this->montar('America/Bogota', '2026-08-24 08:00');
        $this->elegir($this->horariosOfrecidos(), '2026-08-24');

        $this->assertTextosEnHoraLocal(local: '15:00', utc: '20:00');
    }

    /** AC-07.4 · Santiago después del cambio de hora: ídem, con UTC−3. */
    public function test_ac_07_4_ningun_texto_al_cliente_lleva_hora_utc_en_santiago(): void
    {
        $this->fakes();
        $this->montar('America/Santiago', '2026-09-03 08:00');
        $this->elegir($this->horariosOfrecidos(), '2026-09-08');

        $this->assertTextosEnHoraLocal(local: '15:00', utc: '18:00');
    }

    /**
     * AC-07.4 · La confirmación nombra el día **local**, no el de UTC.
     *
     * Con Bogotá (UTC−5) un turno a las 21:00 locales es el **día siguiente** en
     * UTC. Si el texto se armara sobre la columna sin convertir, el cliente
     * leería "miércoles 26" para un turno del martes 25 — el mismo error que la
     * hora corrida, pero peor, porque no se nota mirando el reloj.
     */
    public function test_ac_07_4_la_confirmacion_nombra_el_dia_local_y_no_el_de_utc(): void
    {
        $this->fakes();
        // El negocio abre 21:00-22:00: en Bogotá eso cae al día siguiente en UTC.
        $this->montar('America/Bogota', '2026-08-25 08:00', apertura: '21:00', cierre: '22:00');

        $booking = $this->elegir($this->horariosOfrecidos(), '2026-08-25');

        // La fila cruzó al día siguiente en UTC: es lo correcto.
        $this->assertSame('2026-08-26 02:00:00', $this->startTimeCrudo());

        $texto = $this->ultimoTexto()['text']['body'];

        $this->assertStringContainsString('martes 25 de agosto', $texto,
            'La confirmacion nombro el dia UTC en vez del dia del tenant.');
        $this->assertStringContainsString('21:00', $texto);
        $this->assertStringNotContainsString('miércoles', $texto);
    }

    // ------------------------------------------------- versión de tzdata

    /**
     * La versión de la base de husos, fijada como piso.
     *
     * ⚠️ *"Un `tzdata` desactualizado produce justo el desplazamiento de una hora
     * que AC-07.3 prohíbe, y lo hace sólo unas semanas al año."* Es la nota de
     * riesgo del ticket y **la mitad de su valor**: los tests de arriba pueden
     * estar todos en verde y el turno salir corrido igual, porque el que se
     * equivoca es el sistema operativo, no el código.
     *
     * Los países de la región cambian sus reglas seguido y sin aviso: Chile
     * corrió las fechas varias veces, México eliminó el horario de verano en
     * 2022 y Paraguay lo eliminó en 2024. Una imagen construida con `tzdata`
     * viejo sigue aplicando la regla derogada.
     *
     * Este test **falla el build** si la imagen retrocede de versión. No hay
     * techo a propósito: adelantarse es siempre correcto, atrasarse nunca.
     *
     * ⚠️ Los dos pisos son distintos porque son **dos bases separadas dentro del
     * mismo contenedor**: la de PHP (timelib, la que usa Carbon y por lo tanto
     * todo el producto) está al día, y la de ICU —que viaja adentro de la
     * extensión `intl`— viene atrasada en la imagen `php:8.4-cli`. Ver
     * `test_la_conversion_del_producto_no_pasa_por_la_base_atrasada_de_icu`.
     */
    public function test_la_version_de_tzdata_de_php_no_es_anterior_a_la_fijada(): void
    {
        $actual = timezone_version_get();

        $this->assertGreaterThanOrEqual(
            $this->comparable(self::TZDATA_PHP_MINIMA),
            $this->comparable($actual),
            "La base de husos de PHP es {$actual}, anterior a la fijada (".self::TZDATA_PHP_MINIMA.'). '
            .'Una regla derogada sigue vigente en el contenedor y los turnos salen corridos una hora '
            .'unas semanas al año, sin ningún error visible.'
        );
    }

    /** El mismo piso para la base de husos de ICU, que es otra y va por su cuenta. */
    public function test_la_version_de_tzdata_de_icu_no_es_anterior_a_la_fijada(): void
    {
        $this->assertTrue(extension_loaded('intl'), 'Falta la extension intl que declara el Dockerfile.');

        $actual = intltz_get_tz_data_version();

        $this->assertGreaterThanOrEqual(
            $this->comparable(self::TZDATA_ICU_MINIMA),
            $this->comparable($actual),
            "La base de husos de ICU es {$actual}, anterior a la fijada (".self::TZDATA_ICU_MINIMA.').'
        );
    }

    /**
     * ⚠️ **Hallazgo:** la base de husos de ICU está atrasada y se equivoca.
     *
     * En la imagen actual conviven dos bases:
     *
     * | Base | Versión | La usa |
     * | :-- | :-- | :-- |
     * | PHP / timelib | 2026.3 | `DateTime`, Carbon, `HoraLocal`, todo el producto |
     * | ICU (`intl`) | 2024b | `IntlDateFormatter`, `IntlTimeZone` |
     *
     * Y no coinciden: para `America/Asuncion`, ICU 2024b sigue aplicando el
     * horario de verano que **Paraguay derogó en 2024** y devuelve UTC−4 de
     * marzo a octubre, cuando el país está fijo en UTC−3. Son siete meses al año
     * con una hora de diferencia, no unas semanas.
     *
     * Hoy no es un bug del producto porque `HoraLocal` formatea con Carbon, que
     * usa la base de PHP. Este test es el **alambre de tropiezo**: si alguien
     * reescribe la conversión sobre `IntlDateFormatter` —tentador, porque
     * formatea nombres de mes por locale— los turnos paraguayos empiezan a salir
     * una hora corridos y este test se pone en rojo el mismo día.
     */
    public function test_la_conversion_del_producto_no_pasa_por_la_base_atrasada_de_icu(): void
    {
        $tenant = Tenant::create([
            'name' => 'Barbería Asunción', 'slug' => 'py-'.uniqid(),
            'status' => 'active', 'timezone' => 'America/Asuncion',
        ]);

        // Julio de 2026: Paraguay está en UTC-3 desde que derogó el horario de
        // verano. ICU 2024b todavía cree que es UTC-4.
        $instante = CarbonImmutable::parse('2026-07-15 18:00:00', 'UTC');

        $this->assertSame('15:00', HoraLocal::hora($instante, $tenant),
            'La conversion devolvio la hora de la regla derogada: se esta resolviendo el huso con la base de ICU, '
            .'que en esta imagen esta atrasada.');
    }

    // ------------------------------------------------------------ helpers

    /**
     * Normaliza `2026.3` (PHP) y `2024b` (ICU) a un entero comparable.
     *
     * Los dos formatos codifican lo mismo —año y número de publicación dentro
     * del año— con notación distinta: `2024b` es la segunda publicación de 2024,
     * igual que `2024.2`. Compararlos como cadenas daría `2026.10 < 2026.3`.
     */
    private function comparable(string $version): int
    {
        if (preg_match('/^(\d{4})\.(\d+)$/', $version, $m)) {
            return ((int) $m[1]) * 100 + (int) $m[2];
        }

        if (preg_match('/^(\d{4})([a-z])$/', $version, $m)) {
            return ((int) $m[1]) * 100 + (ord($m[2]) - ord('a') + 1);
        }

        $this->fail("Formato de version de tzdata desconocido: {$version}");
    }

    /** ¿El `dateTime` que viajó a Google es el mismo instante que la fila? */
    private function assertMismoInstante(string $rfc3339, CarbonImmutable $enLaBase): void
    {
        $this->assertSame(
            $enLaBase->utc()->format('Y-m-d H:i:s'),
            CarbonImmutable::parse($rfc3339)->utc()->format('Y-m-d H:i:s'),
            "El evento de Google ({$rfc3339}) y la fila de bookings describen instantes distintos: "
            .'alguien convirtio de mas o de menos.'
        );
    }

    /**
     * AC-07.4 · Ningún cuerpo que salió hacia el cliente contiene la hora UTC.
     *
     * Se recorren **todos** los mensajes salientes —la lista interactiva y los
     * textos—, no sólo la confirmación: el criterio dice "todo texto que se le
     * manda al cliente", y un título de fila con la hora en UTC es tan dañino
     * como un recordatorio mal escrito.
     *
     * No alcanza con prohibir la hora UTC: una conversión a **una tercera zona**
     * —la del servidor, la de otro tenant— no contiene la hora UTC y pasaría el
     * filtro igual. Por eso además se exige que cada fila de la lista muestre
     * exactamente la hora local esperada.
     */
    private function assertTextosEnHoraLocal(string $local, string $utc): void
    {
        $vistos = 0;

        foreach ($this->aMeta() as $mensaje) {
            $cuerpo = json_encode($mensaje, JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString($utc, $cuerpo,
                "Se filtro la hora UTC ({$utc}) a un mensaje del cliente: {$cuerpo}");

            if (str_contains($cuerpo, $local)) {
                $vistos++;
            }

            foreach ($mensaje['interactive']['action']['sections'][0]['rows'] ?? [] as $fila) {
                if (ListaDeHorarios::horarioDe(IdSellado::leer($fila['id'])->accion) === null) {
                    continue;   // fila de «ver más»
                }

                $this->assertSame("{$local} hs", $fila['title'],
                    "Una fila de la lista se muestra a las {$fila['title']} y el turno es a las {$local} locales.");
            }
        }

        $this->assertGreaterThan(0, $vistos,
            "Ningun mensaje mostro la hora local ({$local}): el test no probo nada.");
    }

    /** El último texto que salió hacia el cliente. */
    private function ultimoTexto(): ?array
    {
        foreach (array_reverse($this->aMeta()) as $m) {
            if (($m['type'] ?? null) === 'text') {
                return $m;
            }
        }

        return null;
    }
}
