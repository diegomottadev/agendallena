<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Conversacion\Pausa;
use App\Jobs\ProcessMessageJob;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-025 · Pausar y reactivar el bot desde el panel.
 *
 * La mecánica de la pausa es de T-018b y ya está cubierta por
 * `PausaEInactividadTest`. Acá se prueba **el camino que abre el panel**: que el
 * botón calle al bot de verdad, que quede registrado quién lo apretó, y que el
 * aislamiento por tenant siga en pie cuando la pausa la dispara un humano.
 */
class PausaDesdeElPanelTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/panel/conversaciones';

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private const TELEFONO = '5493764278402';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', self::TZ));
        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ andamiaje

    private function tenant(string $slug = 'piloto', bool $conWhatsApp = true): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Peluquería '.$slug, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        if ($conWhatsApp) {
            Integration::create([
                'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
                'account_identifier' => self::PHONE_NUMBER_ID, 'access_token' => 'meta',
                'settings' => ['verify_token' => 'tok'], 'status' => 'connected',
            ]);
        }

        return $tenant;
    }

    private function usuario(Tenant $tenant, Role $rol = Role::Owner, string $nombre = 'Vanina'): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $nombre,
            'email' => $rol->value.'@'.$tenant->slug.'.test',
            'password' => 'secreto123',
            'role' => $rol,
        ]);
    }

    private function conversacion(
        Tenant $tenant,
        string $telefono = self::TELEFONO,
        Estado $estado = Estado::Idle,
        ?string $nombre = 'Cliente Uno',
    ): Conversation {
        TenantContext::set($tenant->id);

        $c = Conversation::create([
            'user_phone' => $telefono,
            'user_name' => $nombre,
            'current_state' => $estado->value,
            'last_interaction_at' => now(),
        ]);

        TenantContext::forget();

        return $c;
    }

    /** Un mensaje entrante de WhatsApp, para ver si el bot contesta o calla. */
    private function llegaUnMensaje(string $de = self::TELEFONO, ?Tenant $tenant = null): void
    {
        if ($tenant !== null) {
            // El job no hereda el tenant del request: viaja en el payload y lo
            // resuelve por `phone_number_id`. Se publica igual para las lecturas
            // que el propio test hace después.
            TenantContext::set($tenant->id);
        }

        (new ProcessMessageJob(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [[
                'from' => $de, 'id' => 'wamid.'.uniqid(), 'type' => 'text', 'text' => ['body' => 'Hola'],
            ]]],
        ]]]]]))->handle();
    }

    private function mensajesAMeta(): int
    {
        $n = 0;

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com')) {
                $n++;
            }
        }

        return $n;
    }

    // ---------------------------------------------- AC-21.1 · pausar y ver

    /** AC-21.1 · Con el botón apretado, el bot deja de responderle a ese cliente. */
    public function test_pausar_desde_el_panel_calla_al_bot(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));
        $c = $this->conversacion($tenant);

        $this->post(self::URL."/{$c->id}/pausar")->assertRedirect(self::URL);

        $this->llegaUnMensaje(tenant: $tenant);

        $this->assertSame(0, $this->mensajesAMeta(),
            'El bot contestó por encima del operador que apretó "pausar".');
    }

    /** AC-21.1 · La conversación queda con el tiempo restante a la vista. */
    public function test_el_listado_muestra_el_tiempo_restante(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));
        $c = $this->conversacion($tenant);

        $this->post(self::URL."/{$c->id}/pausar");

        $this->travel(20)->minutes();

        $this->get(self::URL)
            ->assertOk()
            ->assertSee(self::TELEFONO)
            ->assertSee('Vuelve solo en '.(Pausa::MINUTOS - 20).' min');
    }

    // ------------------------------------------- AC-21.2 · reactivación

    /** AC-21.2 · Al reactivar, el bot vuelve a contestar de inmediato. */
    public function test_reactivar_devuelve_el_bot_de_inmediato(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));
        $c = $this->conversacion($tenant);

        $this->post(self::URL."/{$c->id}/pausar");
        $this->llegaUnMensaje(tenant: $tenant);
        $this->assertSame(0, $this->mensajesAMeta(), 'El bot contestó estando pausado.');

        $this->post(self::URL."/{$c->id}/reactivar")->assertRedirect(self::URL);

        TenantContext::set($tenant->id);
        $this->assertFalse(Pausa::estaPausada($c->fresh()),
            'La reactivación no levantó la pausa: el bot sigue callado.');

        $this->llegaUnMensaje(tenant: $tenant);

        $this->assertGreaterThan(0, $this->mensajesAMeta(),
            'Reactivado el bot, sigue sin contestar: hay que esperar el vencimiento igual.');
    }

    /**
     * AC-21.2 · Y retoma **desde el estado en que había quedado**.
     *
     * Se verifica sobre `current_state` y no sobre la respuesta del bot a
     * propósito: la pausa vive en Redis justamente para no tocar el estado. Si
     * mandara la conversación a `IDLE`, un cliente a mitad de una reserva
     * perdería el flujo por haber preguntado algo que le contestó un humano.
     */
    public function test_reactivar_no_pierde_el_estado_de_la_conversacion(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));
        $c = $this->conversacion($tenant, estado: Estado::GatheringParams);

        $this->post(self::URL."/{$c->id}/pausar");
        $this->post(self::URL."/{$c->id}/reactivar");

        TenantContext::set($tenant->id);

        $this->assertSame(Estado::GatheringParams->value, $c->fresh()->current_state,
            'La pausa se llevó puesto el estado: el cliente pierde el flujo a medias.');
    }

    // ---------------------------------------- AC-21.3 · vencimiento solo

    /** AC-21.3 · Si nadie reactiva, el bot vuelve al vencer la ventana. */
    public function test_la_pausa_manual_vence_sola(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));
        $c = $this->conversacion($tenant);

        $this->post(self::URL."/{$c->id}/pausar");

        $this->travel(Pausa::MINUTOS + 1)->minutes();

        $this->llegaUnMensaje(tenant: $tenant);

        $this->assertGreaterThan(0, $this->mensajesAMeta(),
            'Vencida la ventana, el bot sigue mudo: la pausa quedó pegada.');
    }

    // --------------------------------- AC-21.4 · quién pausó, y de qué tipo

    /** AC-21.4 · El listado dice quién la pausó y cuándo. */
    public function test_el_listado_muestra_quien_pauso_y_cuando(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant, nombre: 'Vanina Gómez'));
        $c = $this->conversacion($tenant);

        $this->post(self::URL."/{$c->id}/pausar");

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('Pausado desde el panel')
            ->assertSee('Lo pausó Vanina Gómez', false)
            // RNF-02 · La hora se muestra en la zona del tenant, no en UTC.
            ->assertSee('24/08/2026 10:00');
    }

    /** AC-21.4 · Y una pausa automática se lee distinto de una manual. */
    public function test_la_pausa_automatica_se_distingue_de_la_manual(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));
        $c = $this->conversacion($tenant);

        TenantContext::set($tenant->id);
        Pausa::activar($c, Pausa::ORIGEN_AUTOMATICO);
        TenantContext::forget();

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('Pausado automáticamente', false)
            ->assertDontSee('Pausado desde el panel')
            ->assertDontSee('Lo pausó', false);
    }

    /** Registra el id del usuario, no solo que "alguien" pausó. */
    public function test_registra_el_usuario_que_aprieta_el_boton(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $this->actingAs($usuario);
        $c = $this->conversacion($tenant);

        $this->post(self::URL."/{$c->id}/pausar");

        TenantContext::set($tenant->id);
        $detalle = Pausa::detalle($c);

        $this->assertSame(Pausa::ORIGEN_MANUAL, $detalle['origen']);
        $this->assertSame((string) $usuario->id, $detalle['usuario_id']);
    }

    // ------------------------------------------------------ aislamiento

    /** Pausar una conversación no calla al bot para los demás clientes. */
    public function test_la_pausa_no_afecta_a_las_demas_conversaciones(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));

        $pausada = $this->conversacion($tenant, '5493764111111', nombre: 'La pausada');
        $otra = $this->conversacion($tenant, '5493764222222', nombre: 'La otra');

        $this->post(self::URL."/{$pausada->id}/pausar");

        TenantContext::set($tenant->id);
        $this->assertTrue(Pausa::estaPausada($pausada->fresh()));
        $this->assertFalse(Pausa::estaPausada($otra->fresh()),
            'Pausar una conversación calló al bot para otro cliente del mismo negocio.');

        $this->llegaUnMensaje('5493764222222', $tenant);

        $this->assertGreaterThan(0, $this->mensajesAMeta(),
            'El bot dejó de atender a un cliente que nadie pausó.');
    }

    /** RNF-01 · Un usuario de otro tenant no puede pausar una conversación ajena. */
    public function test_no_se_puede_pausar_la_conversacion_de_otro_tenant(): void
    {
        $ajeno = $this->tenant('ajeno');
        $c = $this->conversacion($ajeno);

        $mio = $this->tenant('mio', conWhatsApp: false);
        $this->actingAs($this->usuario($mio));

        $this->post(self::URL."/{$c->id}/pausar")->assertForbidden();

        TenantContext::set($ajeno->id);
        $this->assertFalse(Pausa::estaPausada($c->fresh()),
            'Un usuario de otro tenant pausó el bot de un negocio ajeno.');
    }

    /** Y tampoco reactivarla: el 403 va en las dos operaciones. */
    public function test_no_se_puede_reactivar_la_conversacion_de_otro_tenant(): void
    {
        $ajeno = $this->tenant('ajeno');
        $c = $this->conversacion($ajeno);

        TenantContext::set($ajeno->id);
        Pausa::activar($c, Pausa::ORIGEN_MANUAL, '999');
        TenantContext::forget();

        $mio = $this->tenant('mio', conWhatsApp: false);
        $this->actingAs($this->usuario($mio));

        $this->post(self::URL."/{$c->id}/reactivar")->assertForbidden();

        TenantContext::set($ajeno->id);
        $this->assertTrue(Pausa::estaPausada($c->fresh()),
            'Un usuario de otro tenant le devolvió el bot a un negocio ajeno.');
    }

    /** El listado solo muestra las conversaciones del propio tenant. */
    public function test_el_listado_no_muestra_conversaciones_de_otro_tenant(): void
    {
        $ajeno = $this->tenant('ajeno');
        $this->conversacion($ajeno, '5493764999999', nombre: 'Cliente ajeno');

        $mio = $this->tenant('mio', conWhatsApp: false);
        $this->actingAs($this->usuario($mio));

        $this->get(self::URL)
            ->assertOk()
            ->assertDontSee('5493764999999');
    }

    // ------------------------------------------------------ autorización

    /** Pausar es de los tres roles: staff atiende a mano y necesita el botón. */
    public function test_staff_puede_pausar(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant, Role::Staff));
        $c = $this->conversacion($tenant);

        $this->get(self::URL)->assertOk();
        $this->post(self::URL."/{$c->id}/pausar")->assertRedirect(self::URL);

        TenantContext::set($tenant->id);
        $this->assertTrue(Pausa::estaPausada($c->fresh()));
    }

    /** Sin sesión no hay panel. */
    public function test_sin_login_no_hay_listado(): void
    {
        $this->get(self::URL)->assertRedirect('/login');
    }
}
