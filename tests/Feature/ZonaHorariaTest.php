<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\HoraLocal;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RNF-02 · La base guarda UTC; a las personas se les muestra su hora local.
 *
 * Es una de las dos invariantes que se violan **en silencio**: el sistema sigue
 * funcionando y devuelve datos mal. Estos tests existen para que deje de ser
 * silenciosa.
 */
class ZonaHorariaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function tenant(string $tz = 'America/Argentina/Buenos_Aires'): Tenant
    {
        return Tenant::create([
            'name' => 'PyME', 'slug' => 'piloto-'.uniqid(),
            'status' => 'active', 'timezone' => $tz,
        ]);
    }

    /** El proceso corre en UTC: ninguna escritura depende de la zona del servidor. */
    public function test_la_aplicacion_corre_en_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'),
            'APP_TIMEZONE dejo de ser UTC: las escrituras pasan a depender de la zona del proceso.');
    }

    /** Lo que se persiste es UTC, no la hora del tenant. */
    public function test_la_base_guarda_utc_aunque_el_tenant_sea_argentino(): void
    {
        $tenant = $this->tenant('America/Argentina/Buenos_Aires');

        // 18:00 en Buenos Aires = 21:00 UTC (UTC-3).
        $enBuenosAires = Carbon::parse('2026-08-19 18:00:00', 'America/Argentina/Buenos_Aires');

        TenantContext::runAs($tenant->id, function () use ($enBuenosAires) {
            Conversation::create([
                'user_phone' => '5491133334444',
                'current_state' => 'IDLE',
                'last_interaction_at' => $enBuenosAires,
            ]);
        });

        $crudo = DB::table('conversations')->value('last_interaction_at');

        $this->assertStringStartsWith('2026-08-19 21:00', $crudo,
            "Se guardo {$crudo} en vez del equivalente UTC: la base dejo de estar en UTC.");
    }

    /** Y al mostrarlo vuelve a la hora del tenant. */
    public function test_al_mostrarlo_vuelve_a_la_hora_del_tenant(): void
    {
        $tenant = $this->tenant('America/Argentina/Buenos_Aires');
        $utc = Carbon::parse('2026-08-19 21:00:00', 'UTC');

        $this->assertSame('18:00', HoraLocal::hora($utc, $tenant));
        $this->assertSame('19/08/2026 18:00', HoraLocal::corta($utc, $tenant));
        $this->assertStringContainsString('18:00', HoraLocal::completa($utc, $tenant));
    }

    /** El mismo instante se ve distinto en cada tenant: eso es lo correcto. */
    public function test_el_mismo_instante_se_muestra_distinto_segun_el_tenant(): void
    {
        $utc = Carbon::parse('2026-08-19 21:00:00', 'UTC');

        $this->assertSame('18:00', HoraLocal::hora($utc, $this->tenant('America/Argentina/Buenos_Aires')));
        $this->assertSame('16:00', HoraLocal::hora($utc, $this->tenant('America/Bogota')));
        $this->assertSame('15:00', HoraLocal::hora($utc, $this->tenant('America/Mexico_City')));
    }

    /** Convertir no puede modificar el objeto que recibió. */
    public function test_convertir_no_muta_el_instante_original(): void
    {
        $tenant = $this->tenant('America/Bogota');
        $utc = Carbon::parse('2026-08-19 21:00:00', 'UTC');

        HoraLocal::hora($utc, $tenant);

        $this->assertSame('UTC', $utc->timezone->getName(),
            'Se modifico el instante original: quien lo paso se encuentra el atributo cambiado.');
    }

    /** El texto para recordatorios sale en español y en hora local (AC-07.4). */
    public function test_el_texto_de_recordatorio_va_en_espaniol_y_hora_local(): void
    {
        $tenant = $this->tenant('America/Argentina/Buenos_Aires');
        $utc = Carbon::parse('2026-08-25 18:30:00', 'UTC');   // martes 25, 15:30 en BA

        $texto = HoraLocal::completa($utc, $tenant);

        $this->assertStringContainsString('martes', $texto);
        $this->assertStringContainsString('agosto', $texto);
        $this->assertStringContainsString('15:30', $texto);
        $this->assertStringNotContainsString('18:30', $texto, 'Se filtro la hora UTC al texto del cliente.');
    }

    /** La pantalla de agenda muestra la hora local del tenant, no UTC. */
    public function test_el_panel_muestra_la_hora_local(): void
    {
        $tenant = $this->tenant('America/Argentina/Buenos_Aires');
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Dueño',
            'email' => 'owner@piloto.test', 'password' => 'secreto123', 'role' => Role::Owner,
        ]);

        $html = $this->actingAs($user)->get('/panel/configuracion/agenda')->assertOk()->getContent();

        $enBuenosAires = now()->setTimezone('America/Argentina/Buenos_Aires')->format('H:i');

        $this->assertStringContainsString($enBuenosAires, $html,
            'La pantalla no muestra la hora de Buenos Aires.');
    }
}
