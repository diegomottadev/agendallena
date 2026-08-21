<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Conversacion\Fallback;
use App\Conversacion\Transicion;
use App\Jobs\ProcessMessageJob;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-018c · `ERROR_FALLBACK`: libera lo reservado y vuelve a `IDLE`.
 *
 * Es la decisión de T-007 sobre el hueco que la matriz de flujos no definía.
 */
class FallbackTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    private Integration $meta;

    private Integration $google;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', self::TZ));
        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $this->meta = Integration::create([
            'tenant_id' => $this->tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => self::PHONE_NUMBER_ID, 'access_token' => 'meta',
            'settings' => ['verify_token' => 'tok'], 'status' => 'connected',
        ]);

        $this->google = Integration::create([
            'tenant_id' => $this->tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio@peluqueria.com', 'access_token' => 'ya29',
            'refresh_token' => '1//r', 'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        TenantContext::set($this->tenant->id);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    private function conversacion(Estado $estado, array $contexto = []): Conversation
    {
        return Conversation::create([
            'user_phone' => '5493764278402',
            'current_state' => $estado->value,
            'context_data' => $contexto,
            'last_interaction_at' => now(),
        ]);
    }

    private function config(): BusinessSetting
    {
        return BusinessSetting::withoutTenantScope()->where('tenant_id', $this->tenant->id)->firstOrFail();
    }

    private function fallback(): Fallback
    {
        return app(Fallback::class);
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

    private function borradosEnGoogle(): array
    {
        $out = [];
        foreach (Http::recorded() as [$req, $res]) {
            if ($req->method() === 'DELETE' && str_contains($req->url(), '/events/')) {
                $out[] = $req->url();
            }
        }

        return $out;
    }

    // ---------------------------------------- la decisión de T-007

    /** Vuelve a `IDLE` y limpia lo que había a medias. */
    public function test_vuelve_a_idle_y_limpia_el_contexto(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]])]);

        $c = $this->conversacion(Estado::SlotSelected, [
            'nombre' => 'María', 'horario' => '2026-08-24T15:00:00Z',
        ]);

        $this->fallback()->manejar($c, $this->meta, $this->config());

        $fresca = $c->fresh();
        $this->assertSame('IDLE', $fresca->current_state);
        $this->assertSame([], $fresca->context_data ?? [],
            'Se arrastro el horario de un flujo que se rompio.');
    }

    /** RNF-03 · El cliente recibe el mensaje de cortesía, nunca silencio. */
    public function test_el_cliente_recibe_el_mensaje_de_cortesia(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]])]);

        $c = $this->conversacion(Estado::SelectingSlot);

        $this->fallback()->manejar($c, $this->meta, $this->config());

        $enviados = $this->aMeta();
        $this->assertCount(1, $enviados);
        $this->assertSame($this->config()->fallback_message, $enviados[0]['text']['body']);
    }

    /** El texto es el que configuró el negocio (T-017). */
    public function test_usa_el_texto_de_cortesia_del_tenant(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]])]);

        $config = $this->config();
        $config->fallback_message = 'Uy, se nos complicó el sistema. Ya te contactamos.';
        $config->save();

        $c = $this->conversacion(Estado::SelectingSlot);
        $this->fallback()->manejar($c, $this->meta, $config->fresh());

        $this->assertSame('Uy, se nos complicó el sistema. Ya te contactamos.',
            $this->aMeta()[0]['text']['body']);
    }

    /** Entra desde cualquier estado en curso. */
    public function test_entra_desde_cualquier_estado_en_curso(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]])]);

        foreach ([Estado::GatheringParams, Estado::SelectingSlot, Estado::SlotSelected] as $estado) {
            $c = Conversation::create([
                'user_phone' => '549'.random_int(1000000000, 9999999999),
                'current_state' => $estado->value,
                'last_interaction_at' => now(),
            ]);

            $this->fallback()->manejar($c, $this->meta, $this->config());

            $this->assertSame('IDLE', $c->fresh()->current_state,
                "No se recupero desde {$estado->value}.");
        }
    }

    // ------------------------------------- lo que NO se toca

    /**
     * Un turno ya agendado **no se toca**.
     *
     * Si Google falla después de que el turno quedó creado, borrarlo sería
     * destruir algo real por un problema que ya pasó.
     */
    public function test_no_toca_un_turno_ya_agendado(): void
    {
        Http::fake();

        foreach ([Estado::Booked, Estado::Confirmed] as $terminal) {
            $c = Conversation::create([
                'user_phone' => '549'.random_int(1000000000, 9999999999),
                'current_state' => $terminal->value,
                'last_interaction_at' => now(),
            ]);

            $this->fallback()->manejar($c, $this->meta, $this->config());

            $this->assertSame($terminal->value, $c->fresh()->current_state,
                "Un fallo de Google movio un turno {$terminal->value}.");
        }

        Http::assertNothingSent();
    }

    // ------------------------------------- liberación del evento huérfano

    /** Un evento sin su fila se borra del calendario. */
    public function test_libera_el_evento_que_quedo_sin_su_fila(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response('', 204),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]]),
        ]);

        $c = $this->conversacion(Estado::SlotSelected);

        $this->fallback()->manejar(
            $c, $this->meta, $this->config(),
            eventoHuerfano: 'evt_huerfano_1', google: $this->google,
        );

        $borrados = $this->borradosEnGoogle();
        $this->assertCount(1, $borrados, 'No se libero el evento huerfano.');
        $this->assertStringContainsString('evt_huerfano_1', $borrados[0]);
    }

    /** Sin huérfano no se llama a Google. */
    public function test_sin_evento_huerfano_no_llama_al_calendario(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]])]);

        $this->fallback()->manejar($this->conversacion(Estado::SelectingSlot), $this->meta, $this->config());

        $this->assertEmpty($this->borradosEnGoogle());
    }

    /**
     * Si el borrado falla, **queda registro** — es el hueco H-04.
     *
     * El horario se queda bloqueado por un turno que nunca existió, y T-030
     * necesita poder encontrarlo.
     */
    public function test_si_no_puede_liberar_el_evento_queda_registro(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response('boom', 500),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]]),
        ]);

        $capturado = [];
        Log::listen(function ($m) use (&$capturado) {
            if (($m->context['codigo'] ?? null) === 'EVENTO_HUERFANO_PERSISTE') {
                $capturado[] = $m->context;
            }
        });

        $c = $this->conversacion(Estado::SlotSelected);

        $this->fallback()->manejar(
            $c, $this->meta, $this->config(),
            eventoHuerfano: 'evt_que_no_se_borra', google: $this->google,
        );

        $this->assertNotEmpty($capturado, 'No quedo rastro del evento que sigue bloqueando el horario.');
        $this->assertSame('evt_que_no_se_borra', $capturado[0]['external_event_id']);

        // Y el cliente igual recibe su mensaje: nuestro problema no es su problema.
        $this->assertCount(1, $this->aMeta());
    }

    /** Un evento ya borrado en Google (410) cuenta como liberado. */
    public function test_un_evento_ya_inexistente_cuenta_como_liberado(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response('', 410),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]]),
        ]);

        $liberados = [];
        Log::listen(function ($m) use (&$liberados) {
            if (($m->context['codigo'] ?? null) === 'EVENTO_LIBERADO') {
                $liberados[] = $m->context;
            }
        });

        $this->fallback()->manejar(
            $this->conversacion(Estado::SlotSelected), $this->meta, $this->config(),
            eventoHuerfano: 'evt_ya_borrado', google: $this->google,
        );

        $this->assertNotEmpty($liberados, 'Un 410 se trato como fallo de borrado.');
    }

    // ------------------------------------------------- integración con el flujo

    /**
     * El caso completo: el insert falla, el evento se libera y el cliente
     * recibe cortesía.
     */
    public function test_el_flujo_completo_libera_el_evento_cuando_el_insert_falla(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]]),
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response('', 204),
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['id' => 'evt_creado']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);

        // Se recorre el flujo hasta tener un horario elegible.
        $mensaje = fn (string $t) => ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [[
                'from' => '5493764278402', 'id' => 'wamid.'.uniqid(),
                'type' => 'text', 'text' => ['body' => $t],
            ]]],
        ]]]]];

        (new ProcessMessageJob($mensaje('Hola')))->handle();
        $reservar = $this->aMeta()[0]['interactive']['action']['buttons'][0]['reply']['id'];

        $toca = fn (string $id) => ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [[
                'from' => '5493764278402', 'id' => 'wamid.'.uniqid(), 'type' => 'interactive',
                'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => 'x']],
            ]]],
        ]]]]];

        (new ProcessMessageJob($toca($reservar)))->handle();
        (new ProcessMessageJob($mensaje('María')))->handle();

        $idHorario = null;
        foreach ($this->aMeta() as $m) {
            if (($m['interactive']['type'] ?? null) === 'list') {
                $idHorario = $m['interactive']['action']['sections'][0]['rows'][0]['id'];
                break;
            }
        }
        $this->assertNotNull($idHorario);

        // Se rompe la tabla para que el insert falle.
        DB::statement('RENAME TABLE bookings TO bookings_bak');

        try {
            (new ProcessMessageJob($toca($idHorario)))->handle();
        } finally {
            DB::statement('RENAME TABLE bookings_bak TO bookings');
        }

        // El evento huerfano se borro.
        $this->assertNotEmpty($this->borradosEnGoogle(),
            'El evento quedo bloqueando el horario en el calendario de la PyME.');

        // La conversacion se recupero.
        $c = Conversation::first();
        $this->assertSame('IDLE', $c->current_state);

        // Y el cliente supo que algo paso.
        $enviados = $this->aMeta();
        $ultimo = end($enviados);
        $this->assertStringContainsString('actualizándose', $ultimo['text']['body'] ?? '');
    }
}
