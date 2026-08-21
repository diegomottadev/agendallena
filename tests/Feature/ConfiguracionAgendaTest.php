<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Agenda\GeneradorDeHorarios;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-015 · Configuración de agenda.
 *
 * El recorte Pareto difiere la jornada partida por día: la pantalla maneja un
 * solo rango. AC-15.6 (validaciones) **no** se recorta.
 */
class ConfiguracionAgendaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/panel/configuracion/agenda';

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function entrarComo(Role $rol = Role::Owner, string $tz = 'America/Argentina/Buenos_Aires'): User
    {
        $tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => $tz,
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => $rol->etiqueta(),
            'email' => $rol->value.'@piloto.test', 'password' => 'secreto123', 'role' => $rol,
        ]);

        $this->actingAs($user);

        return $user;
    }

    private function configDe(User $u): BusinessSetting
    {
        return BusinessSetting::withoutTenantScope()->where('tenant_id', $u->tenant_id)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function formulario(array $sobreescribir = []): array
    {
        return array_merge([
            'dias' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'apertura' => '09:00',
            'cierre' => '18:00',
            'slot_duration_minutes' => 30,
            'buffer_minutes' => 10,
            'timezone' => 'America/Argentina/Buenos_Aires',
        ], $sobreescribir);
    }

    // ------------------------------------------------------------- guardado

    public function test_guarda_dias_horario_duracion_y_buffer(): void
    {
        $u = $this->entrarComo();

        $this->put(self::URL, $this->formulario([
            'dias' => ['mon', 'wed', 'fri'],
            'apertura' => '10:00', 'cierre' => '19:00',
            'slot_duration_minutes' => 45, 'buffer_minutes' => 10,
        ]))->assertRedirect(self::URL);

        $c = $this->configDe($u);

        $this->assertSame(45, $c->slot_duration_minutes);
        $this->assertSame(10, $c->buffer_minutes);
        $this->assertSame([['10:00', '19:00']], $c->rangosDe('mon'));
        $this->assertTrue($c->abreEl('wed'));
        // Los dias destildados quedan cerrados, no con el horario anterior.
        $this->assertFalse($c->abreEl('tue'));
        $this->assertFalse($c->abreEl('sun'));
    }

    /** AC-15.3 · La zona horaria va a `tenants.timezone`, no duplicada. */
    public function test_la_zona_horaria_se_guarda_en_el_tenant(): void
    {
        $u = $this->entrarComo();

        $this->put(self::URL, $this->formulario(['timezone' => 'America/Bogota']));

        $this->assertSame('America/Bogota', $u->tenant->fresh()->timezone);
    }

    // ------------------------------------------------- AC-15.6 · validaciones

    /** AC-15.6 · Cierre anterior a la apertura se rechaza y no persiste nada. */
    public function test_rechaza_un_cierre_anterior_a_la_apertura(): void
    {
        $u = $this->entrarComo();
        $original = $this->configDe($u)->rangosDe('mon');

        $this->put(self::URL, $this->formulario(['apertura' => '18:00', 'cierre' => '09:00']))
            ->assertInvalid(['cierre']);

        $this->assertSame($original, $this->configDe($u)->rangosDe('mon'));
    }

    /** AC-15.6 · Buffer mayor que la duración se rechaza. */
    public function test_rechaza_un_buffer_mayor_que_la_duracion(): void
    {
        $u = $this->entrarComo();

        $this->put(self::URL, $this->formulario(['slot_duration_minutes' => 20, 'buffer_minutes' => 30]))
            ->assertInvalid(['buffer_minutes']);
    }

    /**
     * Mismo modo de falla que AC-15.6 aunque el criterio no lo nombre: una
     * configuración que guarda bien y no produce ni un turno.
     */
    public function test_rechaza_una_jornada_mas_corta_que_un_turno(): void
    {
        $u = $this->entrarComo();

        $this->put(self::URL, $this->formulario([
            'apertura' => '09:00', 'cierre' => '09:30', 'slot_duration_minutes' => 45,
        ]))->assertInvalid(['cierre']);
    }

    public function test_exige_al_menos_un_dia(): void
    {
        $this->entrarComo();

        $this->put(self::URL, $this->formulario(['dias' => []]))->assertInvalid(['dias']);
    }

    public function test_rechaza_una_zona_horaria_inexistente(): void
    {
        $this->entrarComo();

        $this->put(self::URL, $this->formulario(['timezone' => 'America/Springfield']))
            ->assertInvalid(['timezone']);
    }

    // ------------------------------------------------ AC-15.2 · grilla horaria

    /**
     * AC-15.2 · Con turnos de 45 y buffer de 10, desde las 09:00 salen 09:00,
     * 09:55, 10:50. El paso es duración + buffer, no duración a secas.
     */
    public function test_la_grilla_respeta_duracion_mas_buffer(): void
    {
        $u = $this->entrarComo();
        $this->put(self::URL, $this->formulario([
            'apertura' => '09:00', 'cierre' => '18:00',
            'slot_duration_minutes' => 45, 'buffer_minutes' => 10,
        ]));

        $gen = new GeneradorDeHorarios($this->configDe($u));
        $lunes = CarbonImmutable::parse('2026-08-24', 'America/Argentina/Buenos_Aires');

        $horas = array_map(
            fn (CarbonImmutable $t) => $t->format('H:i'),
            $gen->paraElDia($lunes, 'America/Argentina/Buenos_Aires')
        );

        $this->assertSame(['09:00', '09:55', '10:50'], array_slice($horas, 0, 3));
    }

    /**
     * El último turno tiene que **terminar** antes del cierre.
     *
     * Ofrecer las 17:45 con turnos de 45 y cierre a las 18:00 agenda a alguien
     * hasta las 18:30: el cliente llega puntual y el local está cerrando.
     */
    public function test_el_ultimo_turno_termina_antes_del_cierre(): void
    {
        $u = $this->entrarComo();
        $this->put(self::URL, $this->formulario([
            'apertura' => '09:00', 'cierre' => '18:00',
            'slot_duration_minutes' => 45, 'buffer_minutes' => 10,
        ]));

        $gen = new GeneradorDeHorarios($this->configDe($u));
        $horas = $gen->paraElDia(
            CarbonImmutable::parse('2026-08-24', 'America/Argentina/Buenos_Aires'),
            'America/Argentina/Buenos_Aires'
        );

        $ultimo = end($horas);
        $this->assertTrue(
            $ultimo->addMinutes(45)->format('H:i') <= '18:00',
            "El ultimo turno ({$ultimo->format('H:i')}) termina despues del cierre."
        );
    }

    /** AC-15.1 · Un día sin atención no ofrece horarios. */
    public function test_un_dia_cerrado_no_ofrece_horarios(): void
    {
        $u = $this->entrarComo();
        $this->put(self::URL, $this->formulario(['dias' => ['mon', 'tue', 'wed', 'thu', 'fri']]));

        $gen = new GeneradorDeHorarios($this->configDe($u));
        $sabado = CarbonImmutable::parse('2026-08-22', 'America/Argentina/Buenos_Aires');

        $this->assertFalse($gen->atiendeEl($sabado, 'America/Argentina/Buenos_Aires'));
        $this->assertSame([], $gen->paraElDia($sabado, 'America/Argentina/Buenos_Aires'));
    }

    /** AC-15.1 · Y además indica cuáles son los días de atención. */
    public function test_informa_los_dias_de_atencion(): void
    {
        $u = $this->entrarComo();
        $this->put(self::URL, $this->formulario(['dias' => ['mon', 'wed', 'fri']]));

        $gen = new GeneradorDeHorarios($this->configDe($u));

        $this->assertSame('lunes, miércoles y viernes', $gen->diasDeAtencionEnTexto());
    }

    /** La grilla se genera en la zona del tenant, no en UTC (RNF-02). */
    public function test_la_grilla_se_genera_en_la_zona_del_tenant(): void
    {
        $u = $this->entrarComo(Role::Owner, 'America/Bogota');
        $this->put(self::URL, $this->formulario([
            'apertura' => '09:00', 'cierre' => '18:00', 'timezone' => 'America/Bogota',
        ]));

        $gen = new GeneradorDeHorarios($this->configDe($u));
        $primero = $gen->paraElDia(CarbonImmutable::parse('2026-08-24', 'UTC'), 'America/Bogota')[0];

        $this->assertSame('09:00', $primero->format('H:i'));
        $this->assertSame('America/Bogota', $primero->timezone->getName());
    }

    /** La jornada partida cargada por seeder sigue generando los dos tramos. */
    public function test_la_grilla_soporta_jornada_partida(): void
    {
        $u = $this->entrarComo();
        $c = $this->configDe($u);

        $c->business_hours = ['mon' => [['09:00', '11:00'], ['16:00', '18:00']]] + $c->business_hours;
        $c->slot_duration_minutes = 60;
        $c->buffer_minutes = 0;
        $c->save();

        $gen = new GeneradorDeHorarios($c->fresh());
        $horas = array_map(
            fn (CarbonImmutable $t) => $t->format('H:i'),
            $gen->paraElDia(CarbonImmutable::parse('2026-08-24', 'America/Argentina/Buenos_Aires'), 'America/Argentina/Buenos_Aires')
        );

        $this->assertSame(['09:00', '10:00', '16:00', '17:00'], $horas);
    }

    // --------------------------------------------------------- autorizacion

    public function test_staff_no_puede_configurar_la_agenda(): void
    {
        $this->entrarComo(Role::Staff);

        $this->get(self::URL)->assertForbidden();
        $this->put(self::URL, $this->formulario())->assertForbidden();
    }

    public function test_la_pantalla_carga_para_owner(): void
    {
        $this->entrarComo();

        $this->get(self::URL)->assertOk()->assertSee('Días de atención', escape: false);
    }
}
