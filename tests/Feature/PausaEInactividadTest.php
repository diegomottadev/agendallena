<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Conversacion\MaquinaDeEstados;
use App\Conversacion\Pausa;
use App\Conversacion\Transicion;
use App\Jobs\ProcessMessageJob;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-018b · Pausa por intervención humana y expiración por inactividad.
 *
 * ⚠️ **Contradicción resuelta acá:** la matriz de flujos dice que al vencer la
 * pausa se va a `IDLE`; AC-08.2 y AC-21.2 dicen *"retoma desde el estado en que
 * quedó"*. Gana el criterio: mandar a `IDLE` haría que un cliente a mitad de una
 * reserva pierda todo el flujo porque el operador le contestó una duda.
 */
class PausaEInactividadTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', self::TZ));
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

        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);

        TenantContext::set($this->tenant->id);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    private function conversacion(Estado $estado = Estado::Idle, string $tel = '5493764278402'): Conversation
    {
        return Conversation::create([
            'user_phone' => $tel,
            'current_state' => $estado->value,
            'last_interaction_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function mensaje(string $texto = 'Hola', string $de = '5493764278402'): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [[
                'from' => $de, 'id' => 'wamid.'.uniqid(), 'type' => 'text', 'text' => ['body' => $texto],
            ]]],
        ]]]]];
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

    // -------------------------------------------------- AC-08.1 · el silencio

    /** Con la conversación pausada, el bot no responde. */
    public function test_el_bot_no_responde_mientras_esta_pausada(): void
    {
        $c = $this->conversacion();
        Pausa::activar($c, Pausa::ORIGEN_MANUAL, 'usuario-1');

        (new ProcessMessageJob($this->mensaje('Hola?')))->handle();

        $this->assertSame(0, $this->mensajesAMeta(), 'El bot contesto por encima del operador.');
    }

    /** AC-08.1 · Pero el mensaje **sí** queda registrado. */
    public function test_el_mensaje_queda_registrado_aunque_el_bot_calle(): void
    {
        $c = $this->conversacion();
        Pausa::activar($c);

        (new ProcessMessageJob($this->mensaje('¿Me atienden?')))->handle();

        $this->assertSame(1, Message::count(), 'Se perdio el mensaje recibido durante la pausa.');
        $this->assertSame('¿Me atienden?', Message::first()->content);
    }

    /**
     * AC-08.2 · El estado **no se toca** durante la pausa.
     *
     * Es la contradicción que este ticket resuelve: la matriz mandaba a `IDLE`.
     */
    public function test_la_pausa_conserva_el_estado_de_la_conversacion(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);
        $c->forceFill(['context_data' => ['nombre' => 'María', 'servicio' => 'corte']])->save();

        Pausa::activar($c);
        (new ProcessMessageJob($this->mensaje('una consulta')))->handle();

        $fresca = $c->fresh();
        $this->assertSame('SELECTING_SLOT', $fresca->current_state,
            'La pausa movio el estado: el cliente perderia la reserva a medias.');
        $this->assertSame('María', $fresca->context_data['nombre']);
    }

    /** AC-21.2 · Al reactivar, el bot retoma desde donde quedó. */
    public function test_al_reactivar_retoma_desde_donde_quedo(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);
        Pausa::activar($c, Pausa::ORIGEN_MANUAL, 'usuario-1');

        Pausa::levantar($c, 'usuario-1');

        $this->assertFalse(Pausa::estaPausada($c));
        $this->assertSame('SELECTING_SLOT', $c->fresh()->current_state);
    }

    /** AC-21.3 · Si nadie reactiva, la pausa vence sola. */
    public function test_la_pausa_vence_sola(): void
    {
        $c = $this->conversacion();
        Pausa::activar($c);

        $this->assertTrue(Pausa::estaPausada($c));

        $this->travel(Pausa::MINUTOS + 1)->minutes();

        $this->assertFalse(Pausa::estaPausada($c), 'La pausa no vencio a los 60 minutos.');
    }

    /** Cada mensaje durante la pausa corre la ventana. */
    public function test_un_mensaje_durante_la_pausa_refresca_el_ttl(): void
    {
        $c = $this->conversacion();
        Pausa::activar($c);

        $this->travel(50)->minutes();
        (new ProcessMessageJob($this->mensaje('sigo acá')))->handle();

        // Sin refresco, a los 65 minutos ya habria vencido.
        $this->travel(15)->minutes();

        $this->assertTrue(Pausa::estaPausada($c),
            'El bot se desperto a mitad de una conversacion humana.');
    }

    /** AC-08.3 · Pausar una conversación no calla al bot para las demás. */
    public function test_la_pausa_no_afecta_a_otras_conversaciones(): void
    {
        $pausada = $this->conversacion(tel: '5491111111111');
        $this->conversacion(tel: '5492222222222');

        Pausa::activar($pausada);

        (new ProcessMessageJob($this->mensaje('Hola', '5492222222222')))->handle();

        $this->assertGreaterThan(0, $this->mensajesAMeta(),
            'Pausar una conversacion callo al bot para otro cliente.');
    }

    /** AC-21.4 · Se sabe quién pausó, cuándo y cuánto falta. */
    public function test_registra_quien_pauso_y_cuanto_falta(): void
    {
        $c = $this->conversacion();
        Pausa::activar($c, Pausa::ORIGEN_MANUAL, 'usuario-42');

        $detalle = Pausa::detalle($c);

        $this->assertSame(Pausa::ORIGEN_MANUAL, $detalle['origen']);
        $this->assertSame('usuario-42', $detalle['usuario_id']);
        $this->assertSame(Pausa::MINUTOS, $detalle['minutos_restantes']);

        $this->travel(45)->minutes();
        $this->assertSame(15, Pausa::detalle($c)['minutos_restantes']);
    }

    /** Una pausa manual se distingue de una automática. */
    public function test_distingue_la_pausa_manual_de_la_automatica(): void
    {
        $manual = $this->conversacion(tel: '5491111111111');
        $auto = $this->conversacion(tel: '5492222222222');

        Pausa::activar($manual, Pausa::ORIGEN_MANUAL, 'usuario-1');
        Pausa::activar($auto, Pausa::ORIGEN_AUTOMATICO);

        $this->assertSame(Pausa::ORIGEN_MANUAL, Pausa::detalle($manual)['origen']);
        $this->assertSame(Pausa::ORIGEN_AUTOMATICO, Pausa::detalle($auto)['origen']);
        $this->assertNull(Pausa::detalle($auto)['usuario_id']);
    }

    // ------------------------------------ T-007 · inactividad de 30 minutos

    /** Una conversación abandonada vuelve a `IDLE`. */
    public function test_expira_una_conversacion_abandonada(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);
        $c->forceFill(['last_interaction_at' => now()->subMinutes(31)])->save();

        $this->artisan('conversaciones:expirar')->assertSuccessful();

        $this->assertSame('IDLE', $c->fresh()->current_state);
    }

    /** El contexto se limpia: los datos de un flujo abandonado no valen. */
    public function test_al_expirar_limpia_el_contexto(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);
        $c->forceFill([
            'last_interaction_at' => now()->subMinutes(31),
            'context_data' => ['nombre' => 'María', 'horario' => '2026-08-24T12:00:00Z'],
        ])->save();

        $this->artisan('conversaciones:expirar');

        $this->assertSame([], $c->fresh()->context_data ?? [],
            'Se arrastraron datos de un flujo que el cliente abandono.');
    }

    /** Una conversación reciente no se toca. */
    public function test_no_expira_una_conversacion_activa(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);
        $c->forceFill(['last_interaction_at' => now()->subMinutes(10)])->save();

        $this->artisan('conversaciones:expirar');

        $this->assertSame('SELECTING_SLOT', $c->fresh()->current_state);
    }

    /** Un turno agendado no se expira: borraría el rastro de algo real. */
    public function test_no_expira_un_turno_ya_agendado(): void
    {
        $c = $this->conversacion(Estado::Booked);
        $c->forceFill(['last_interaction_at' => now()->subDays(3)])->save();

        $this->artisan('conversaciones:expirar');

        $this->assertSame('BOOKED', $c->fresh()->current_state);
    }

    /** Una conversación pausada no expira: el operador la está atendiendo. */
    public function test_no_expira_una_conversacion_pausada(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);
        $c->forceFill(['last_interaction_at' => now()->subMinutes(45)])->save();

        Pausa::activar($c, Pausa::ORIGEN_MANUAL, 'usuario-1');

        $this->artisan('conversaciones:expirar');

        $this->assertSame('SELECTING_SLOT', $c->fresh()->current_state,
            'Se expiro una conversacion que un humano estaba atendiendo.');
    }

    /** El comando expira conversaciones de todos los tenants. */
    public function test_expira_conversaciones_de_varios_tenants(): void
    {
        $otro = Tenant::create([
            'name' => 'Estética Norte', 'slug' => 'estetica',
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $a = $this->conversacion(Estado::SelectingSlot);
        $a->forceFill(['last_interaction_at' => now()->subMinutes(31)])->save();

        $b = TenantContext::runAs($otro->id, fn () => Conversation::create([
            'user_phone' => '5495555555555',
            'current_state' => Estado::GatheringParams->value,
            'last_interaction_at' => now()->subMinutes(31),
        ]));

        $this->artisan('conversaciones:expirar');

        $this->assertSame('IDLE', $a->fresh()->current_state);
        $this->assertSame('IDLE', $b->fresh()->current_state);
    }

    /** `--dry-run` no toca nada. */
    public function test_dry_run_no_modifica_nada(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);
        $c->forceFill(['last_interaction_at' => now()->subMinutes(31)])->save();

        $this->artisan('conversaciones:expirar --dry-run');

        $this->assertSame('SELECTING_SLOT', $c->fresh()->current_state);
    }

    /** Tras expirar, el cliente que vuelve recibe la bienvenida. */
    public function test_tras_expirar_el_cliente_recibe_la_bienvenida(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);
        $c->forceFill(['last_interaction_at' => now()->subMinutes(31)])->save();

        $this->artisan('conversaciones:expirar');

        (new ProcessMessageJob($this->mensaje('Hola de nuevo')))->handle();

        $this->assertSame('GATHERING_PARAMS', $c->fresh()->current_state);
        $this->assertGreaterThan(0, $this->mensajesAMeta());
    }
}
