<?php

namespace Tests\Feature;

use App\Conversacion\Interactivo\IdSellado;
use App\Conversacion\ListaDeHorarios;
use App\Jobs\ProcessMessageJob;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El flujo de reserva de punta a punta, hasta donde llega hoy.
 *
 * Une T-018a (estados), T-019 (interactivos), T-021 (bienvenida), T-022
 * (disponibilidad), T-023 (lista) y T-024 (sin disponibilidad). Es el primer
 * test que recorre lo que el cliente final realmente experimenta.
 */
class FlujoReservaTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    /** @var array<int,array<string,mixed>> */
    private array $enviados = [];

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
            'tenant_id' => $this->tenant->id,
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => self::PHONE_NUMBER_ID,
            'access_token' => 'token-meta',
            'settings' => ['verify_token' => 'tok'],
            'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $this->tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio@peluqueria.com',
            'access_token' => 'ya29.token',
            'refresh_token' => '1//refresh',
            'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    /** @param array<int,array{0:string,1:string}> $ocupados */
    private function fakes(array $ocupados = []): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => [
                    'busy' => array_map(fn ($p) => ['start' => $p[0], 'end' => $p[1]], $ocupados),
                ]],
            ]),
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.OUT_'.uniqid()]],
            ]),
        ]);
    }

    /** @return array<string,mixed> */
    private function texto(string $cuerpo, string $id = null): array
    {
        return $this->payload(['from' => '5493764278402', 'id' => $id ?? 'wamid.'.uniqid(),
            'type' => 'text', 'text' => ['body' => $cuerpo]]);
    }

    /** @return array<string,mixed> */
    private function toca(string $idBoton, string $id = null): array
    {
        return $this->payload(['from' => '5493764278402', 'id' => $id ?? 'wamid.'.uniqid(),
            'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $idBoton, 'title' => 'x']]]);
    }

    /** @return array<string,mixed> */
    private function payload(array $mensaje): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [$mensaje]],
        ]]]]];
    }

    /** Los cuerpos que se le mandaron a Meta, en orden. */
    private function salientes(): array
    {
        $out = [];

        foreach (Http::recorded() as [$request, $response]) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                $out[] = $request->data();
            }
        }

        return $out;
    }

    /**
     * Recorre el flujo hasta que la lista de horarios está en pantalla.
     *
     * T-026 sumó un paso: tocar «Reservar» pregunta el nombre (decisión T-007)
     * y recién con la respuesta se ofrecen los horarios.
     */
    private function hastaLosHorarios(string $nombre = 'María'): void
    {
        (new ProcessMessageJob($this->texto('Hola')))->handle();

        $reservar = $this->salientes()[0]['interactive']['action']['buttons'][0]['reply']['id'];
        (new ProcessMessageJob($this->toca($reservar)))->handle();

        (new ProcessMessageJob($this->texto($nombre)))->handle();
    }

    /** El último mensaje interactivo que salió (la lista). */
    private function ultimaLista(): ?array
    {
        foreach (array_reverse($this->salientes()) as $m) {
            if (($m['interactive']['type'] ?? null) === 'list') {
                return $m;
            }
        }

        return null;
    }

    /** El último texto que salió. */
    private function ultimoTexto(): ?array
    {
        foreach (array_reverse($this->salientes()) as $m) {
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

    // ------------------------------------------------------- el recorrido

    /** Saludar, tocar «Reservar» y recibir horarios reales. */
    public function test_de_hola_a_la_lista_de_horarios(): void
    {
        $this->fakes();

        // 1 · El cliente escribe por primera vez.
        (new ProcessMessageJob($this->texto('Hola')))->handle();

        $bienvenida = $this->salientes()[0];
        $this->assertSame('interactive', $bienvenida['type']);
        $this->assertStringContainsString('Peluquería Sur', $bienvenida['interactive']['body']['text']);

        $botonReservar = $bienvenida['interactive']['action']['buttons'][0]['reply']['id'];

        // 2 · Toca «Reservar un turno» → el bot pide el nombre (T-026).
        (new ProcessMessageJob($this->toca($botonReservar)))->handle();

        $pregunta = $this->ultimoTexto();
        $this->assertStringContainsString('nombre', $pregunta['text']['body']);

        // 3 · Da su nombre y recién ahí llegan los horarios.
        (new ProcessMessageJob($this->texto('María')))->handle();

        $lista = $this->ultimaLista();
        $this->assertNotNull($lista, 'Nunca se ofrecio la lista de horarios.');

        $filas = $lista['interactive']['action']['sections'][0]['rows'];
        $this->assertNotEmpty($filas, 'No se ofrecio ningun horario.');

        // 4 · Cada fila resuelve a un instante concreto.
        $sello = IdSellado::leer($filas[0]['id']);
        $this->assertNotNull(ListaDeHorarios::horarioDe($sello->accion));

        $this->assertSame('SELECTING_SLOT', $this->conversacion()->current_state);
    }

    /** Los horarios ofrecidos no chocan con lo que hay en el calendario. */
    public function test_no_ofrece_horarios_ocupados(): void
    {
        // Toda la mañana del lunes ocupada.
        $this->fakes([['2026-08-24T08:00:00-03:00', '2026-08-24T13:00:00-03:00']]);

        $this->hastaLosHorarios();

        $filas = $this->ultimaLista()['interactive']['action']['sections'][0]['rows'];

        foreach ($filas as $fila) {
            $sello = IdSellado::leer($fila['id']);
            $horario = ListaDeHorarios::horarioDe($sello->accion);

            if ($horario === null) {
                continue;   // fila de "ver mas"
            }

            $local = $horario->setTimezone(self::TZ);

            if ($local->toDateString() === '2026-08-24') {
                $this->assertGreaterThanOrEqual('13:00', $local->format('H:i'),
                    "Se ofrecio {$local->format('H:i')}, dentro de la franja ocupada.");
            }
        }
    }

    /**
     * AC-19.4 · Con la agenda llena se explica, no se calla.
     *
     * ⚠️ **Cambió el canal, no el criterio.** Hasta T-035 este mensaje salía
     * como texto plano: la opción «Hablar con una persona» que devolvía T-024 se
     * descartaba y el cliente no tenía nada que tocar. Ahora sale como mensaje
     * interactivo con ese botón —que es el origen `no_availability` de US-20—,
     * así que la aserción mira el cuerpo del interactivo. El texto es el mismo.
     */
    public function test_con_la_agenda_llena_le_avisa_al_cliente(): void
    {
        // Toda la ventana ocupada.
        $this->fakes([['2026-08-24T00:00:00-03:00', '2026-09-30T00:00:00-03:00']]);

        $this->hastaLosHorarios();

        $salientes = $this->salientes();
        $respuesta = end($salientes);

        $this->assertNotNull($respuesta);
        $this->assertStringContainsString('turnos disponibles', $respuesta['interactive']['body']['text']);
        $this->assertSame(
            'Hablar con alguien',
            $respuesta['interactive']['action']['buttons'][0]['reply']['title'],
        );
    }

    /** RNF-03 · Si Google se cae, mensaje de cortesía y chat no colgado. */
    public function test_si_google_falla_responde_con_cortesia(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response('boom', 500),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X'.uniqid()]]]),
        ]);

        $this->hastaLosHorarios();

        $config = BusinessSetting::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();

        $this->assertSame($config->fallback_message, $this->ultimoTexto()['text']['body']);
    }

    /** AC-19.4 · Sin calendario conectado no se dice "no hay lugar". */
    public function test_sin_calendario_conectado_no_miente_al_cliente(): void
    {
        Integration::where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)->delete();
        $this->fakes();

        $this->hastaLosHorarios();

        $this->assertStringNotContainsString('turnos disponibles', $this->ultimoTexto()['text']['body'],
            'Un problema de configuracion nuestro se comunico como agenda llena.');
    }

    /** AC-03.2 · Un botón de la bienvenida vieja no reabre el menú. */
    public function test_un_boton_viejo_no_ejecuta_la_accion(): void
    {
        $this->fakes();

        (new ProcessMessageJob($this->texto('Hola')))->handle();
        $boton = $this->salientes()[0]['interactive']['action']['buttons'][0]['reply']['id'];

        // Avanza a SELECTING_SLOT.
        (new ProcessMessageJob($this->toca($boton)))->handle();
        $enviadosAntes = count($this->salientes());

        // Vuelve a tocar el mismo boton de la bienvenida, ya caduco.
        (new ProcessMessageJob($this->toca($boton)))->handle();

        $this->assertCount($enviadosAntes, $this->salientes(),
            'Un boton caduco disparo una accion.');
    }

    /** AC-05.4 · Pedir más horarios no reinicia la conversación. */
    public function test_pedir_mas_horarios_no_reinicia_la_conversacion(): void
    {
        $this->fakes();

        $this->hastaLosHorarios();

        $filas = $this->ultimaLista()['interactive']['action']['sections'][0]['rows'];
        $ultima = end($filas);
        $sello = IdSellado::leer($ultima['id']);

        if (ListaDeHorarios::paginaSolicitada($sello->accion) === null) {
            $this->markTestSkipped('La agenda de prueba no genero suficientes horarios para paginar.');
        }

        (new ProcessMessageJob($this->toca($ultima['id'])))->handle();

        $this->assertNotNull($this->ultimaLista(), 'Pedir mas horarios no devolvio otra lista.');
        $this->assertSame('SELECTING_SLOT', $this->conversacion()->current_state,
            'Pedir mas horarios reinicio la conversacion.');
    }
}
