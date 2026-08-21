<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Agenda\ConsultorDeDisponibilidad;
use App\Services\Agenda\DisponibilidadNoDisponible;
use App\Services\Agenda\Motivo;
use App\Services\Agenda\RespuestaDeDisponibilidad;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-024 · Respuesta cuando no hay disponibilidad.
 *
 * El criterio que no se recorta es **AC-19.4**: los tres vacíos —agenda llena,
 * configuración ausente y error de API— no se comunican igual.
 */
class SinDisponibilidadTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 08:00', self::TZ));

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $this->integration = Integration::create([
            'tenant_id' => $this->tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio@peluqueria.com',
            'access_token' => 'ya29.vigente', 'refresh_token' => '1//refresh',
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

    private function config(array $horarios = null): BusinessSetting
    {
        $c = BusinessSetting::withoutTenantScope()->where('tenant_id', $this->tenant->id)->firstOrFail();

        $c->business_hours = $horarios ?? [
            'mon' => [['09:00', '18:00']], 'tue' => [['09:00', '18:00']],
            'wed' => [['09:00', '18:00']], 'thu' => [['09:00', '18:00']],
            'fri' => [['09:00', '18:00']], 'sat' => [], 'sun' => [],
        ];
        $c->slot_duration_minutes = 60;
        $c->buffer_minutes = 0;
        $c->save();

        return $c->fresh();
    }

    private function consultar(BusinessSetting $config)
    {
        return app(ConsultorDeDisponibilidad::class)->consultar(
            $this->integration, $this->tenant, $config,
            CarbonImmutable::parse('2026-08-24 00:00', self::TZ)
        );
    }

    // ------------------------------------------------- AC-19.4 · los 3 vacíos

    /** Vacío 1 · Agenda llena: es la agenda del cliente, no un bug. */
    public function test_agenda_llena_se_comunica_como_agenda_llena(): void
    {
        $config = $this->config();

        // Toda la semana ocupada.
        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => ['primary' => ['busy' => [
                ['start' => '2026-08-24T00:00:00-03:00', 'end' => '2026-09-01T00:00:00-03:00'],
            ]]],
        ])]);

        $resultado = $this->consultar($config);

        $this->assertSame(Motivo::AgendaLlena, $resultado->motivo);
        $this->assertFalse($resultado->esProblemaNuestro());

        $r = RespuestaDeDisponibilidad::para($resultado, $this->tenant, $config);

        // AC-19.1 · explica el vacío y ofrece una salida.
        $this->assertStringContainsString('turnos disponibles', $r['texto']);
        // T-035 la convirtio en boton de Meta y el titulo admite 20 caracteres:
        // «Hablar con una persona» se veia truncado como «Hablar con una…».
        $this->assertContains('Hablar con alguien', $r['opciones']);
    }

    /**
     * Vacío 2 · Sin configuración: **es un bug nuestro**, no se dice "no hay lugar".
     *
     * Decirle al cliente que no hay lugar cuando en realidad nadie cargó el
     * horario lo manda a buscar turno a otro lado por un problema nuestro.
     */
    public function test_sin_configuracion_no_se_comunica_como_agenda_llena(): void
    {
        $config = $this->config([
            'mon' => [], 'tue' => [], 'wed' => [], 'thu' => [],
            'fri' => [], 'sat' => [], 'sun' => [],
        ]);
        Http::fake();

        $resultado = $this->consultar($config);

        $this->assertSame(Motivo::SinConfiguracion, $resultado->motivo);
        $this->assertTrue($resultado->esProblemaNuestro());

        $r = RespuestaDeDisponibilidad::para($resultado, $this->tenant, $config);

        $this->assertSame($config->fallback_message, $r['texto']);
        $this->assertStringNotContainsString('turnos disponibles', $r['texto'],
            'Un bug nuestro se le comunico al cliente como "no hay lugar".');

        // Y no se le pregunta a Google por un negocio sin horario configurado.
        Http::assertNothingSent();
    }

    /** Vacío 3 · Error de API: no es un resultado, es una interrupción. */
    public function test_un_error_de_api_no_es_un_resultado_de_disponibilidad(): void
    {
        $config = $this->config();
        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response('boom', 500)]);

        $this->expectException(DisponibilidadNoDisponible::class);

        $this->consultar($config);
    }

    /** Los tres vacíos producen textos distintos entre sí. */
    public function test_los_tres_vacios_no_dicen_lo_mismo(): void
    {
        $configLlena = $this->config();
        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => ['primary' => ['busy' => [
                ['start' => '2026-08-24T00:00:00-03:00', 'end' => '2026-09-01T00:00:00-03:00'],
            ]]],
        ])]);
        $textoLleno = RespuestaDeDisponibilidad::para($this->consultar($configLlena), $this->tenant, $configLlena)['texto'];

        $configVacia = $this->config([
            'mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [],
        ]);
        $textoSinConfig = RespuestaDeDisponibilidad::para($this->consultar($configVacia), $this->tenant, $configVacia)['texto'];

        $this->assertNotSame($textoLleno, $textoSinConfig);
    }

    // -------------------------------------------------------- ventana y textos

    /** T-024 cierra la decisión abierta: la ventana es configurable. */
    public function test_la_ventana_de_busqueda_es_configurable(): void
    {
        $config = $this->config();
        $this->assertSame(7, $config->search_window_days, 'El valor por defecto dejo de ser 7 dias.');

        $config->search_window_days = 3;
        $config->save();

        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => ['primary' => ['busy' => []]],
        ])]);

        $this->consultar($config->fresh());

        Http::assertSent(function ($request) {
            $d = $request->data();
            $desde = CarbonImmutable::parse($d['timeMin']);
            $hasta = CarbonImmutable::parse($d['timeMax']);

            // `diffInDays` devuelve float con signo en Carbon 3: se normaliza.
            return (int) round(abs($desde->diffInDays($hasta))) === 3;
        });
    }

    /** El mensaje dice en cuántos días buscó: "no tengo lugar" suena definitivo. */
    public function test_el_mensaje_de_agenda_llena_dice_el_plazo_consultado(): void
    {
        $config = $this->config();
        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => ['primary' => ['busy' => [
                ['start' => '2026-08-24T00:00:00-03:00', 'end' => '2026-09-01T00:00:00-03:00'],
            ]]],
        ])]);

        $r = RespuestaDeDisponibilidad::para($this->consultar($config), $this->tenant, $config);

        $this->assertStringContainsString('7 días', $r['texto']);
    }

    /** El texto de agenda llena es el que configuró el negocio (T-017). */
    public function test_usa_el_texto_configurado_por_el_negocio(): void
    {
        $config = $this->config();
        $config->no_availability_message = 'Uf, esta semana estamos a full.';
        $config->save();

        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => ['primary' => ['busy' => [
                ['start' => '2026-08-24T00:00:00-03:00', 'end' => '2026-09-01T00:00:00-03:00'],
            ]]],
        ])]);

        $r = RespuestaDeDisponibilidad::para($this->consultar($config->fresh()), $this->tenant, $config->fresh());

        $this->assertStringContainsString('Uf, esta semana estamos a full.', $r['texto']);
    }

    /** Ninguna rama termina sin mensaje: el `match` es exhaustivo. */
    public function test_ningun_motivo_queda_sin_respuesta(): void
    {
        $config = $this->config();

        foreach (Motivo::cases() as $motivo) {
            $resultado = match ($motivo) {
                Motivo::HayHorarios => \App\Services\Agenda\ResultadoDisponibilidad::conHorarios(
                    [CarbonImmutable::parse('2026-08-24 09:00', self::TZ)], 7
                ),
                Motivo::AgendaLlena => \App\Services\Agenda\ResultadoDisponibilidad::agendaLlena(7),
                Motivo::SinConfiguracion => \App\Services\Agenda\ResultadoDisponibilidad::sinConfiguracion(),
            };

            $r = RespuestaDeDisponibilidad::para($resultado, $this->tenant, $config);

            $this->assertNotSame('', trim($r['texto']),
                "El motivo {$motivo->value} deja al cliente sin respuesta.");
        }
    }
}
