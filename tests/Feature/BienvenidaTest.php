<?php

namespace Tests\Feature;

use App\Conversacion\Bienvenida;
use App\Conversacion\Estado;
use App\Conversacion\MaquinaDeEstados;
use App\Conversacion\Transicion;
use App\Jobs\ProcessMessageJob;
use App\Meta\NumeroDeWhatsApp;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-021 · Bienvenida y menú inicial.
 *
 * El recorte Pareto difiere la variante fuera de horario (AC-16.4). **La
 * instrumentación del KPI no se recorta.**
 */
class BienvenidaTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        Integration::create([
            'tenant_id' => $this->tenant->id,
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => self::PHONE_NUMBER_ID,
            'access_token' => 'token-vigente',
            'settings' => ['verify_token' => 'tok'],
            'status' => 'connected',
        ]);

        $this->metaAcepta();
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function metaAcepta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'messages' => [['id' => 'wamid.SALIENTE_'.uniqid()]],
        ])]);
    }

    /** @return array<string,mixed> */
    private function payload(string $texto = 'Hola', string $id = null, string $de = '5493764278402'): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
                'messages' => [[
                    'from' => $de,
                    'id' => $id ?? 'wamid.'.uniqid(),
                    'type' => 'text',
                    'text' => ['body' => $texto],
                ]],
            ],
        ]]]]];
    }

    // ------------------------------------------------------------- AC-16.1

    /** El primer mensaje recibe bienvenida con el nombre del negocio. */
    public function test_el_primer_contacto_recibe_la_bienvenida_con_el_nombre_del_negocio(): void
    {
        (new ProcessMessageJob($this->payload('Hola')))->handle();

        Http::assertSent(function ($request) {
            $d = $request->data();

            return ($d['type'] ?? null) === 'interactive'
                && str_contains($d['interactive']['body']['text'], 'Peluquería Sur');
        });
    }

    /** La bienvenida trae opciones para tocar, no pide escribir. */
    public function test_la_bienvenida_trae_opciones_para_tocar(): void
    {
        (new ProcessMessageJob($this->payload()))->handle();

        Http::assertSent(function ($request) {
            $botones = $request->data()['interactive']['action']['buttons'] ?? [];

            return count($botones) >= 1
                && str_contains($botones[0]['reply']['id'], 'reservar');
        });
    }

    /** El saliente queda en el historial (T-048). */
    public function test_la_respuesta_del_bot_queda_en_el_historial(): void
    {
        (new ProcessMessageJob($this->payload()))->handle();

        TenantContext::runAs($this->tenant->id, function () {
            $salientes = Message::where('direction', Message::SALIENTE)->get();

            $this->assertCount(1, $salientes, 'El bot respondio pero no quedo en el historial.');
            $this->assertStringContainsString('Peluquería Sur', $salientes->first()->content);
        });
    }

    /** El texto sale de la configuración del tenant (T-017). */
    public function test_usa_el_texto_de_bienvenida_configurado(): void
    {
        TenantContext::runAs($this->tenant->id, function () {
            $c = \App\Models\BusinessSetting::first();
            $c->welcome_message = 'Bienvenido, ¿en qué te ayudamos?';
            $c->save();
        });

        (new ProcessMessageJob($this->payload()))->handle();

        Http::assertSent(fn ($r) => str_contains(
            $r->data()['interactive']['body']['text'], 'Bienvenido, ¿en qué te ayudamos?'
        ));
    }

    // ------------------------------------------------------------- AC-16.3

    /** La palabra de reinicio vuelve al menú desde cualquier estado. */
    public function test_la_palabra_de_reinicio_vuelve_al_menu_desde_cualquier_estado(): void
    {
        // Se lleva la conversacion hasta la mitad del flujo.
        (new ProcessMessageJob($this->payload('Hola')))->handle();

        $conv = TenantContext::runAs($this->tenant->id, fn () => Conversation::first());
        app(MaquinaDeEstados::class)->aplicar($conv, Transicion::ParametrosCompletos, ['servicio' => 'corte']);
        $this->assertSame('SELECTING_SLOT', $conv->fresh()->current_state);

        (new ProcessMessageJob($this->payload('menú')))->handle();

        // Vuelve al principio: IDLE -> GATHERING_PARAMS por la bienvenida.
        $this->assertSame('GATHERING_PARAMS', $conv->fresh()->current_state);
    }

    /** AC-16.3 · El reinicio **limpia** lo que había a medias. */
    public function test_el_reinicio_limpia_el_estado_anterior(): void
    {
        (new ProcessMessageJob($this->payload('Hola')))->handle();

        $conv = TenantContext::runAs($this->tenant->id, fn () => Conversation::first());
        app(MaquinaDeEstados::class)->aplicar($conv, Transicion::ParametrosCompletos, [
            'servicio' => 'corte', 'nombre' => 'María',
        ]);
        $this->assertSame('corte', $conv->fresh()->context_data['servicio']);

        (new ProcessMessageJob($this->payload('reiniciar')))->handle();

        $this->assertSame([], $conv->fresh()->context_data ?? [],
            'El reinicio arrastro el servicio que el cliente descarto.');
    }

    /** Se reconoce escrito de cualquier forma. */
    public function test_reconoce_la_palabra_de_reinicio_con_acentos_y_mayusculas(): void
    {
        foreach (['menu', 'Menú', 'MENU!', ' inicio ', 'Empezar'] as $palabra) {
            $this->assertTrue(Bienvenida::esPalabraDeReinicio($palabra), "No reconocio '{$palabra}'.");
        }

        foreach (['menudo', 'quiero el menu del dia', 'hola'] as $noEs) {
            $this->assertFalse(Bienvenida::esPalabraDeReinicio($noEs), "Reconocio '{$noEs}' como reinicio.");
        }
    }

    // ------------------------------- instrumentación (no se recorta)

    /** AC-16.1 · El tiempo webhook → envío queda instrumentado. */
    public function test_instrumenta_la_latencia_hasta_el_envio(): void
    {
        $medido = null;
        Log::listen(function ($m) use (&$medido) {
            if (($m->context['codigo'] ?? null) === 'KPI_RESPUESTA') {
                $medido = $m->context;
            }
        });

        (new ProcessMessageJob($this->payload(), microtime(true)))->handle();

        $this->assertNotNull($medido, 'No se instrumento la latencia del KPI.');
        $this->assertArrayHasKey('latencia_ms', $medido);
        $this->assertTrue($medido['dentro_del_kpi'], 'La respuesta tardo mas de 15 s.');
    }

    // ------------------------------- el 9 argentino

    /** El destinatario se normaliza: Meta rechaza el `wa_id` con el 9. */
    public function test_le_envia_a_meta_el_numero_sin_el_nueve(): void
    {
        (new ProcessMessageJob($this->payload(de: '5493764278402')))->handle();

        Http::assertSent(function ($request) {
            return $request->data()['to'] === '543764278402';
        });
    }

    /** La conversación guarda el `wa_id` tal como llega. */
    public function test_la_conversacion_guarda_el_wa_id_de_meta(): void
    {
        (new ProcessMessageJob($this->payload(de: '5493764278402')))->handle();

        TenantContext::runAs($this->tenant->id, function () {
            $this->assertSame('5493764278402', Conversation::first()->user_phone);
        });
    }

    /** Fuera de Argentina no se toca el número. */
    public function test_no_toca_los_numeros_de_otros_paises(): void
    {
        $this->assertSame('573001234567', NumeroDeWhatsApp::paraEnviar('573001234567'));
        $this->assertSame('5215512345678', NumeroDeWhatsApp::paraEnviar('5215512345678'));
        $this->assertSame('543764278402', NumeroDeWhatsApp::paraEnviar('5493764278402'));
    }

    /** Dos escrituras del mismo teléfono se reconocen iguales. */
    public function test_reconoce_el_mismo_telefono_escrito_distinto(): void
    {
        $this->assertTrue(NumeroDeWhatsApp::sonElMismo('5493764278402', '543764278402'));
        $this->assertTrue(NumeroDeWhatsApp::sonElMismo('+54 9 3764 27-8402', '543764278402'));
        $this->assertFalse(NumeroDeWhatsApp::sonElMismo('5493764278402', '5493764278403'));
    }

    // ------------------------------------------------------------- robustez

    /** Si Meta rechaza el envío, el job no explota. */
    public function test_si_meta_rechaza_el_envio_el_job_no_falla(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131030]], 400)]);

        (new ProcessMessageJob($this->payload()))->handle();

        // El estado avanzo igual: el mensaje se recibio y se proceso.
        TenantContext::runAs($this->tenant->id, function () {
            $this->assertSame('GATHERING_PARAMS', Conversation::first()->current_state);
        });
    }

    /** Un segundo mensaje no vuelve a saludar. */
    public function test_no_saluda_dos_veces(): void
    {
        (new ProcessMessageJob($this->payload('Hola', 'wamid.UNO')))->handle();
        (new ProcessMessageJob($this->payload('Quiero un turno', 'wamid.DOS')))->handle();

        Http::assertSentCount(1);
    }
}
