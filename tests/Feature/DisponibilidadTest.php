<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Agenda\ConsultorDeDisponibilidad;
use App\Services\Agenda\DisponibilidadNoDisponible;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-022 · Consulta de disponibilidad: grilla del negocio menos lo ocupado.
 *
 * La ventana de prueba arranca el **lunes 2026-08-24** para que los días caigan
 * siempre en la misma posición de la semana.
 */
class DisponibilidadTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        // "Ahora" es el domingo anterior: toda la semana queda en el futuro.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 08:00', self::TZ));

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $this->integration = Integration::create([
            'tenant_id' => $this->tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio@peluqueria.com',
            'access_token' => 'ya29.vigente',
            'refresh_token' => '1//refresh',
            // Muy en el futuro a proposito: algun test adelanta el reloj, y un
            // token vencido dispararia el refresco de T-013 en vez de medir
            // la disponibilidad, que es lo que este test verifica.
            'expires_at' => CarbonImmutable::parse('2027-01-01 00:00', 'UTC'),
            'status' => 'connected',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    private function config(int $duracion = 60, int $buffer = 10): BusinessSetting
    {
        $c = BusinessSetting::withoutTenantScope()->where('tenant_id', $this->tenant->id)->firstOrFail();

        $c->business_hours = [
            'mon' => [['09:00', '18:00']], 'tue' => [['09:00', '18:00']],
            'wed' => [['09:00', '18:00']], 'thu' => [['09:00', '18:00']],
            'fri' => [['09:00', '18:00']], 'sat' => [], 'sun' => [],
        ];
        $c->slot_duration_minutes = $duracion;
        $c->buffer_minutes = $buffer;
        $c->save();

        return $c->fresh();
    }

    /** @param array<int,array{0:string,1:string}> $ocupados  pares ISO */
    private function googleResponde(array $ocupados = []): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => [
                    'busy' => array_map(fn ($p) => ['start' => $p[0], 'end' => $p[1]], $ocupados),
                ]],
            ]),
        ]);
    }

    /** @return array<int,string> horas HH:i del lunes */
    private function horariosDelLunes(BusinessSetting $config, int $dias = 1): array
    {
        $libres = app(ConsultorDeDisponibilidad::class)->horariosLibres(
            $this->integration, $this->tenant, $config,
            CarbonImmutable::parse('2026-08-24 00:00', self::TZ), $dias
        );

        return array_map(fn (CarbonImmutable $t) => $t->setTimezone(self::TZ)->format('H:i'), $libres);
    }

    // -------------------------------------------------------------- criterios

    /** AC-05.1 · Con un evento de 10:00 a 11:00, nada se superpone con esa franja. */
    public function test_no_ofrece_horarios_que_se_superpongan_con_un_evento(): void
    {
        $config = $this->config(duracion: 60, buffer: 0);
        $this->googleResponde([['2026-08-24T10:00:00-03:00', '2026-08-24T11:00:00-03:00']]);

        $horas = $this->horariosDelLunes($config);

        $this->assertNotContains('10:00', $horas);
        // Sin buffer, las 11:00 son legítimas: el evento terminó.
        $this->assertContains('11:00', $horas);
        $this->assertContains('09:00', $horas);
    }

    /** AC-05.2 · Con buffer 10 y un turno que termina 11:00, el primero es 11:10 o más. */
    public function test_respeta_el_buffer_despues_de_un_evento(): void
    {
        // Turnos de 50 + buffer 10 = paso de 60, asi la grilla cae en horas justas.
        $config = $this->config(duracion: 50, buffer: 10);
        $this->googleResponde([['2026-08-24T10:00:00-03:00', '2026-08-24T11:00:00-03:00']]);

        $horas = $this->horariosDelLunes($config);

        $this->assertNotContains('10:00', $horas);
        $this->assertNotContains('11:00', $horas, 'Ofrecio las 11:00 pegado al evento: no respeta el buffer.');

        $despues = array_values(array_filter($horas, fn ($h) => $h >= '11:00'));
        $this->assertGreaterThanOrEqual('11:10', $despues[0]);
    }

    /** El buffer también protege **antes** del evento. */
    public function test_respeta_el_buffer_antes_de_un_evento(): void
    {
        $config = $this->config(duracion: 50, buffer: 10);
        $this->googleResponde([['2026-08-24T10:00:00-03:00', '2026-08-24T11:00:00-03:00']]);

        $horas = $this->horariosDelLunes($config);

        // Un turno 09:00-09:50 termina 10 min antes de las 10:00: justo el borde.
        $this->assertContains('09:00', $horas);
        // Pero 09:20-10:10 chocaria; la grilla no lo genera, y si lo hiciera se filtra.
        foreach ($horas as $h) {
            $this->assertTrue($h <= '09:00' || $h >= '11:10', "Se ofrecio {$h}, dentro de la franja protegida.");
        }
    }

    /** AC-05.3 · Nada fuera del horario de atención ni en días no laborables. */
    public function test_no_ofrece_nada_fuera_del_horario_ni_en_dias_cerrados(): void
    {
        $config = $this->config(duracion: 60, buffer: 0);
        $this->googleResponde();

        $libres = app(ConsultorDeDisponibilidad::class)->horariosLibres(
            $this->integration, $this->tenant, $config,
            CarbonImmutable::parse('2026-08-24 00:00', self::TZ), 7
        );

        foreach ($libres as $t) {
            $local = $t->setTimezone(self::TZ);

            $this->assertGreaterThanOrEqual('09:00', $local->format('H:i'));
            $this->assertLessThanOrEqual('17:00', $local->format('H:i'));
            $this->assertNotContains($local->dayOfWeek, [CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY],
                'Se ofrecio un horario en un dia cerrado.');
        }

        $this->assertNotEmpty($libres);
    }

    /** UC-2.8 · Un bloqueo manual de la PyME deja de ofrecerse. */
    public function test_un_bloqueo_manual_en_el_calendario_deja_de_ofrecerse(): void
    {
        $config = $this->config(duracion: 60, buffer: 0);

        /*
         * Secuencia y no dos `Http::fake()` seguidos: al llamar `fake()` dos
         * veces, Laravel **acumula** los stubs y sigue ganando el primero que
         * coincide, asi que la segunda respuesta nunca se usaria y el test
         * pasaria por la razon equivocada.
         */
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::sequence()
                // 1a consulta: calendario vacio.
                ->push(['calendars' => ['primary' => ['busy' => []]]])
                // 2a consulta: la duenia se bloqueo la tarde desde el celular.
                ->push(['calendars' => ['primary' => ['busy' => [
                    ['start' => '2026-08-24T14:00:00-03:00', 'end' => '2026-08-24T18:00:00-03:00'],
                ]]]]),
        ]);

        $sinBloqueo = $this->horariosDelLunes($config);
        $this->assertContains('15:00', $sinBloqueo);

        $conBloqueo = $this->horariosDelLunes($config);

        $this->assertNotContains('15:00', $conBloqueo);
        $this->assertContains('09:00', $conBloqueo);
    }

    // ------------------------------------------------------------- decisiones

    /**
     * Si Google falla, **no se ofrece nada**.
     *
     * Devolver la grilla completa haria que el bot ofrezca horarios ocupados,
     * que segun el propio ticket es peor que no ofrecer nada.
     */
    public function test_si_google_falla_no_ofrece_horarios_en_vez_de_ofrecer_todo(): void
    {
        $config = $this->config();
        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response('boom', 500)]);

        $this->expectException(DisponibilidadNoDisponible::class);

        $this->horariosDelLunes($config);
    }

    /** Un dia sin candidatos no gasta una llamada a Google. */
    public function test_un_dia_cerrado_no_consulta_a_google(): void
    {
        $config = $this->config();
        Http::fake();

        // El domingo 2026-08-30 esta cerrado.
        $libres = app(ConsultorDeDisponibilidad::class)->horariosLibres(
            $this->integration, $this->tenant, $config,
            CarbonImmutable::parse('2026-08-30 00:00', self::TZ), 1
        );

        $this->assertSame([], $libres);
        Http::assertNothingSent();
    }

    /**
     * Una sola llamada para toda la ventana.
     *
     * El spike T-005 midio que el tamaño de la ventana no afecta la latencia:
     * siete llamadas costarian siete veces mas por el mismo dato.
     */
    public function test_consulta_toda_la_ventana_en_una_sola_llamada(): void
    {
        $config = $this->config();
        $this->googleResponde();

        app(ConsultorDeDisponibilidad::class)->horariosLibres(
            $this->integration, $this->tenant, $config,
            CarbonImmutable::parse('2026-08-24 00:00', self::TZ), 7
        );

        Http::assertSentCount(1);
    }

    /** No se ofrecen horarios que ya pasaron. */
    public function test_no_ofrece_horarios_del_pasado(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 15:00', self::TZ));
        $config = $this->config(duracion: 60, buffer: 0);
        $this->googleResponde();

        $horas = $this->horariosDelLunes($config);

        $this->assertNotContains('09:00', $horas, 'Ofrecio un horario que ya paso.');
        $this->assertNotContains('14:00', $horas);
        $this->assertContains('16:00', $horas);
    }

    /** Los horarios vuelven en la zona del tenant, no en UTC. */
    public function test_devuelve_los_horarios_en_la_zona_del_tenant(): void
    {
        $config = $this->config();
        $this->googleResponde();

        $libres = app(ConsultorDeDisponibilidad::class)->horariosLibres(
            $this->integration, $this->tenant, $config,
            CarbonImmutable::parse('2026-08-24 00:00', self::TZ), 1
        );

        $this->assertSame(self::TZ, $libres[0]->timezone->getName());
        $this->assertSame('09:00', $libres[0]->format('H:i'));
    }

    /** La ventana se le pide a Google en UTC (RNF-02). */
    public function test_le_pide_la_ventana_a_google_en_utc(): void
    {
        $config = $this->config();
        $this->googleResponde();

        $this->horariosDelLunes($config);

        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return str_ends_with($cuerpo['timeMin'], 'Z') || str_contains($cuerpo['timeMin'], '+00:00');
        });
    }
}
