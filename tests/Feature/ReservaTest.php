<?php

namespace Tests\Feature;

use App\Conversacion\Interactivo\IdSellado;
use App\Conversacion\ListaDeHorarios;
use App\Jobs\ProcessMessageJob;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Google\CalendarioDeGoogle;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-026 · Reserva: creación del evento en Google Calendar.
 *
 * *"Es el momento donde el producto genera el valor que cobramos."*
 */
class ReservaTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 08:00', self::TZ));
        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => self::TZ,
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    /**
     * Declara los dobles de HTTP. **Se llama una sola vez por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: llamarlo dos veces no reemplaza el
     * primero, y sigue ganando el que coincida primero. Un test que re-fakea a
     * mitad de camino para simular un fallo seguiría recibiendo la respuesta
     * exitosa del `setUp` — y pasaría por la razón equivocada.
     *
     * @param  \Illuminate\Http\Client\Response|null  $alCrearEvento
     */
    private function fakes($alCrearEvento = null): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => $alCrearEvento
                ?? Http::response(['id' => 'evt_google_123']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);
    }

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
     * Recorre el flujo hasta que la lista de horarios está en pantalla.
     *
     * @return string  El `id` de la primera fila (un horario).
     */
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

        $this->fail('Nunca se ofrecio la lista de horarios.');
    }

    /**
     * El último texto que salió.
     *
     * Se busca por tipo y no por posición: `Http::fake()` **resetea lo grabado**,
     * así que los tests que re-fakean a mitad de camino pierden las llamadas
     * anteriores y los índices se corren.
     */
    private function ultimoTexto(): ?array
    {
        foreach (array_reverse($this->aMeta()) as $m) {
            if (($m['type'] ?? null) === 'text') {
                return $m;
            }
        }

        return null;
    }

    private function conversacion(): Conversation
    {
        return TenantContext::runAs($this->tenant->id, fn () => Conversation::first());
    }

    // ----------------------------------------------------------- el recorrido

    /** AC-06.1 · Elegir un horario crea el evento y confirma al cliente. */
    public function test_elegir_un_horario_crea_el_turno_y_lo_confirma(): void
    {
        $this->fakes();
        $idHorario = $this->llegarHastaLaLista();

        (new ProcessMessageJob($this->toca($idHorario)))->handle();

        // El evento se creó en Google.
        $evento = $this->aGoogleEventos();
        $this->assertNotNull($evento, 'No se creo el evento en Google.');
        $this->assertStringContainsString('María', $evento['summary']);

        // La fila quedó persistida.
        $booking = TenantContext::runAs($this->tenant->id, fn () => Booking::first());
        $this->assertNotNull($booking, 'No se persistio la reserva.');
        $this->assertSame('evt_google_123', $booking->external_event_id);
        $this->assertSame('María', $booking->client_name);

        // Y el cliente recibió la confirmación.
        $this->assertStringContainsString('quedó reservado', $this->ultimoTexto()['text']['body']);

        $this->assertSame('BOOKED', $this->conversacion()->current_state);
    }

    /** AC-07.1 · `start_time` y `end_time` en UTC. */
    public function test_los_horarios_se_guardan_en_utc(): void
    {
        $this->fakes();
        $idHorario = $this->llegarHastaLaLista();
        $sello = IdSellado::leer($idHorario);
        $esperado = ListaDeHorarios::horarioDe($sello->accion);

        (new ProcessMessageJob($this->toca($idHorario)))->handle();

        $crudo = DB::table('bookings')->first();

        $this->assertSame(
            $esperado->utc()->format('Y-m-d H:i:s'),
            $crudo->start_time,
            'El turno no se guardo en UTC.'
        );

        // Y el fin respeta la duracion configurada.
        $booking = TenantContext::runAs($this->tenant->id, fn () => Booking::first());
        $this->assertSame(30, (int) $booking->start_time->diffInMinutes($booking->end_time));
    }

    /** El evento queda marcado como creado por nosotros (para T-027 y T-030). */
    public function test_el_evento_queda_marcado_como_creado_por_el_sistema(): void
    {
        $this->fakes();
        (new ProcessMessageJob($this->toca($this->llegarHastaLaLista())))->handle();

        $evento = $this->aGoogleEventos();
        $privadas = $evento['extendedProperties']['private'];

        $this->assertSame(CalendarioDeGoogle::MARCA_ORIGEN, $privadas['origen']);
        $this->assertSame($this->tenant->id, $privadas['tenant_id']);

        $this->assertTrue(CalendarioDeGoogle::loCreamosNosotros($evento));
        $this->assertFalse(CalendarioDeGoogle::loCreamosNosotros(['summary' => 'Almuerzo']));
    }

    /** El evento lleva teléfono y servicio, no solo el nombre. */
    public function test_el_evento_lleva_los_datos_del_cliente(): void
    {
        $this->fakes();
        (new ProcessMessageJob($this->toca($this->llegarHastaLaLista('Juan'))))->handle();

        $evento = $this->aGoogleEventos();

        $this->assertStringContainsString('Juan', $evento['description']);
        $this->assertStringContainsString('5493764278402', $evento['description']);
    }

    /** El evento se crea en la zona del tenant, no en UTC. */
    public function test_el_evento_se_crea_en_la_zona_del_tenant(): void
    {
        $this->fakes();
        (new ProcessMessageJob($this->toca($this->llegarHastaLaLista())))->handle();

        $evento = $this->aGoogleEventos();

        $this->assertSame(self::TZ, $evento['start']['timeZone']);
        $this->assertStringContainsString('-03:00', $evento['start']['dateTime']);
    }

    // ----------------------------------------- captura del nombre (T-007)

    /** T-007 · El nombre se pide en `GATHERING_PARAMS`, antes de los horarios. */
    public function test_pide_el_nombre_antes_de_ofrecer_horarios(): void
    {
        $this->fakes();
        (new ProcessMessageJob($this->texto('Hola')))->handle();
        $reservar = $this->aMeta()[0]['interactive']['action']['buttons'][0]['reply']['id'];

        (new ProcessMessageJob($this->toca($reservar)))->handle();

        $segundo = $this->aMeta()[1];
        $this->assertSame('text', $segundo['type']);
        $this->assertStringContainsString('nombre', $segundo['text']['body']);

        // Todavia no se ofrecieron horarios.
        $this->assertSame('GATHERING_PARAMS', $this->conversacion()->current_state);
    }

    /** El nombre queda en el contexto y llega hasta la reserva. */
    public function test_el_nombre_viaja_desde_gathering_params_hasta_el_turno(): void
    {
        $this->fakes();
        $idHorario = $this->llegarHastaLaLista('Carla Gómez');

        $this->assertSame('Carla Gómez', $this->conversacion()->context_data['nombre']);

        (new ProcessMessageJob($this->toca($idHorario)))->handle();

        $booking = TenantContext::runAs($this->tenant->id, fn () => Booking::first());
        $this->assertSame('Carla Gómez', $booking->client_name);
    }

    /** Un nombre vacío o desmedido se vuelve a pedir. */
    public function test_vuelve_a_pedir_un_nombre_invalido(): void
    {
        $this->fakes();
        (new ProcessMessageJob($this->texto('Hola')))->handle();
        $reservar = $this->aMeta()[0]['interactive']['action']['buttons'][0]['reply']['id'];
        (new ProcessMessageJob($this->toca($reservar)))->handle();

        (new ProcessMessageJob($this->texto(str_repeat('a', 200))))->handle();

        $this->assertStringContainsString('nombre', $this->ultimoTexto()['text']['body']);
        $this->assertSame('GATHERING_PARAMS', $this->conversacion()->current_state);
    }

    // --------------------------------------------------------- fallos

    /**
     * Si Google rechaza el evento, **no queda una reserva fantasma**.
     */
    public function test_si_google_rechaza_el_evento_no_persiste_la_reserva(): void
    {
        // Un solo fake, con el rechazo ya declarado: `Http::fake()` **acumula**
        // stubs, y re-fakear a mitad de camino no reemplaza el anterior.
        $this->fakes(Http::response(['error' => ['message' => 'quota']], 403));

        (new ProcessMessageJob($this->toca($this->llegarHastaLaLista())))->handle();

        $this->assertSame(0, DB::table('bookings')->count(),
            'Quedo una reserva sin evento en el calendario.');

        // T-007 · ERROR_FALLBACK libera y vuelve a IDLE.
        $this->assertSame('IDLE', $this->conversacion()->current_state);
    }

    /** Y el cliente recibe el mensaje de cortesía, no silencio. */
    public function test_si_google_falla_el_cliente_recibe_cortesia(): void
    {
        $this->fakes(Http::response('boom', 500));

        (new ProcessMessageJob($this->toca($this->llegarHastaLaLista())))->handle();

        $this->assertStringContainsString('actualizándose', $this->ultimoTexto()['text']['body']);
    }

    /**
     * ⚠️ La ventana de T-030: si el insert falla, queda registro del huérfano.
     */
    public function test_si_la_reserva_no_persiste_queda_registro_del_evento_huerfano(): void
    {
        $this->fakes();
        $idHorario = $this->llegarHastaLaLista();

        $capturado = [];
        Log::listen(function ($m) use (&$capturado) {
            if (($m->context['codigo'] ?? null) === 'RESERVA_INCONSISTENTE') {
                $capturado[] = $m->context;
            }
        });

        // Se rompe la tabla para forzar el fallo del insert.
        DB::statement('RENAME TABLE bookings TO bookings_bak');

        try {
            (new ProcessMessageJob($this->toca($idHorario)))->handle();
        } finally {
            DB::statement('RENAME TABLE bookings_bak TO bookings');
        }

        $this->assertNotEmpty($capturado, 'No quedo rastro del evento huerfano para T-030.');
        $this->assertSame('evt_google_123', $capturado[0]['external_event_id']);
    }

    /** La confirmación llega **después** de persistir, no antes. */
    public function test_la_confirmacion_se_envia_despues_de_persistir(): void
    {
        $this->fakes();
        $idHorario = $this->llegarHastaLaLista();

        DB::statement('RENAME TABLE bookings TO bookings_bak');

        try {
            (new ProcessMessageJob($this->toca($idHorario)))->handle();
        } finally {
            DB::statement('RENAME TABLE bookings_bak TO bookings');
        }

        foreach ($this->aMeta() as $m) {
            if (($m['type'] ?? null) === 'text') {
                $this->assertStringNotContainsString('quedó reservado', $m['text']['body'],
                    'Se confirmo un turno que nunca se persistio.');
            }
        }
    }

    /** La confirmación muestra la hora local, no UTC. */
    public function test_la_confirmacion_muestra_la_hora_local(): void
    {
        $this->fakes();
        $idHorario = $this->llegarHastaLaLista();
        $sello = IdSellado::leer($idHorario);
        $horario = ListaDeHorarios::horarioDe($sello->accion);

        (new ProcessMessageJob($this->toca($idHorario)))->handle();

        $horaLocal = $horario->setTimezone(self::TZ)->format('H:i');
        $cuerpo = $this->ultimoTexto()['text']['body'];

        $this->assertStringContainsString($horaLocal, $cuerpo);
        $this->assertStringNotContainsString($horario->utc()->format('H:i'), $cuerpo);
    }
}
