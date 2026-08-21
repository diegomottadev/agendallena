<?php

namespace Tests\Feature;

use App\Conversacion\ConversacionOcupada;
use App\Conversacion\Estado;
use App\Conversacion\MaquinaDeEstados;
use App\Conversacion\TablaDeTransiciones;
use App\Conversacion\Transicion;
use App\Conversacion\TransicionNoDeclarada;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-018a · Núcleo de la máquina de estados.
 */
class MaquinaDeEstadosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        TenantContext::set($this->tenant->id);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function conversacion(Estado $estado = Estado::Idle, string $tel = '5491133334444'): Conversation
    {
        return Conversation::create([
            'user_phone' => $tel,
            'current_state' => $estado->value,
            'last_interaction_at' => now(),
        ]);
    }

    private function maquina(): MaquinaDeEstados
    {
        return app(MaquinaDeEstados::class);
    }

    // ------------------------------------------------------- camino feliz

    /** El recorrido completo hasta la reserva. */
    public function test_recorre_el_camino_feliz_hasta_booked(): void
    {
        $c = $this->conversacion();
        $m = $this->maquina();

        $this->assertSame(Estado::GatheringParams, $m->aplicar($c, Transicion::MensajeInicial));
        $this->assertSame(Estado::SelectingSlot, $m->aplicar($c, Transicion::ParametrosCompletos));
        $this->assertSame(Estado::SlotSelected, $m->aplicar($c, Transicion::SlotElegido));
        $this->assertSame(Estado::Booked, $m->aplicar($c, Transicion::ReservaConfirmada));

        $this->assertSame('BOOKED', $c->fresh()->current_state);
    }

    /**
     * T-007 · `CALCULATING_QUOTE` no existe: se va derecho a elegir horario.
     *
     * Si el cotizador vuelve del diferido, este test tiene que fallar — es la
     * señal de que hay que insertar el estado con su transición.
     */
    public function test_no_pasa_por_el_estado_de_cotizacion(): void
    {
        $this->assertSame(
            Estado::SelectingSlot,
            TablaDeTransiciones::destino(Estado::GatheringParams, Transicion::ParametrosCompletos)
        );

        $this->assertFalse(
            in_array('CALCULATING_QUOTE', array_column(Estado::cases(), 'value'), true),
            'CALCULATING_QUOTE volvio al enum sin sus transiciones.'
        );
    }

    // --------------------------------------------- AC-03.4 · lo no declarado

    /** Un evento que no corresponde no cambia el estado. */
    public function test_una_transicion_no_declarada_no_cambia_el_estado(): void
    {
        $c = $this->conversacion(Estado::GatheringParams);

        try {
            $this->maquina()->aplicar($c, Transicion::SlotElegido);
            $this->fail('Se esperaba TransicionNoDeclarada.');
        } catch (TransicionNoDeclarada $e) {
            $this->assertSame(Estado::GatheringParams, $e->estadoActual);
        }

        $this->assertSame('GATHERING_PARAMS', $c->fresh()->current_state,
            'El estado cambio pese a que la transicion no estaba declarada.');
    }

    /** AC-03.4 · La excepción dice qué se esperaba, para poder recordárselo. */
    public function test_la_excepcion_informa_que_se_esperaba(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);

        try {
            $this->maquina()->aplicar($c, Transicion::ConfirmaAsistencia);
            $this->fail('Se esperaba TransicionNoDeclarada.');
        } catch (TransicionNoDeclarada $e) {
            $this->assertContains(Transicion::SlotElegido, $e->esperados);
            $this->assertStringContainsString('slot_elegido', $e->getMessage());
        }
    }

    // ------------------------------------------------------------ comodines

    /** La intervención humana llega desde cualquier estado. */
    public function test_la_intervencion_humana_llega_desde_cualquier_estado(): void
    {
        foreach (Estado::cases() as $estado) {
            $destino = TablaDeTransiciones::destino($estado, Transicion::IntervieneHumano);

            if ($estado === Estado::PausedHuman) {
                $this->assertNull($destino, 'Se puede pausar una conversacion ya pausada.');

                continue;
            }

            $this->assertSame(Estado::PausedHuman, $destino,
                "No se puede pausar desde {$estado->value}.");
        }
    }

    /** El fallo de un tercero no aplica a estados terminales. */
    public function test_el_fallo_de_un_tercero_no_toca_lo_ya_agendado(): void
    {
        $this->assertSame(Estado::ErrorFallback,
            TablaDeTransiciones::destino(Estado::SlotSelected, Transicion::FallaTercero));

        foreach ([Estado::Booked, Estado::Confirmed, Estado::Cancelled] as $terminal) {
            $this->assertNull(
                TablaDeTransiciones::destino($terminal, Transicion::FallaTercero),
                "Un fallo de Google movio un turno ya {$terminal->value}."
            );
        }
    }

    /** T-007 · Desde `ERROR_FALLBACK` se vuelve a `IDLE`. */
    public function test_desde_error_fallback_se_vuelve_a_idle(): void
    {
        $c = $this->conversacion(Estado::SlotSelected);
        $m = $this->maquina();

        $this->assertSame(Estado::ErrorFallback, $m->aplicar($c, Transicion::FallaTercero));
        $this->assertSame(Estado::Idle, $m->aplicar($c, Transicion::FallbackEnviado));
    }

    /** La inactividad expira lo que está en curso, no lo terminado. */
    public function test_la_inactividad_solo_expira_lo_que_esta_en_curso(): void
    {
        foreach ([Estado::GatheringParams, Estado::SelectingSlot, Estado::SlotSelected] as $enCurso) {
            $this->assertSame(Estado::Idle,
                TablaDeTransiciones::destino($enCurso, Transicion::Inactividad));
        }

        foreach ([Estado::Booked, Estado::Confirmed, Estado::Cancelled, Estado::Idle] as $otro) {
            $this->assertNull(
                TablaDeTransiciones::destino($otro, Transicion::Inactividad),
                "La inactividad borro un {$otro->value}."
            );
        }
    }

    // -------------------------------------------- AC-03.1 · contexto y datos

    /** El contexto se conserva a lo largo del flujo. */
    public function test_conserva_los_datos_capturados_entre_estados(): void
    {
        $c = $this->conversacion();
        $m = $this->maquina();

        $m->aplicar($c, Transicion::MensajeInicial);
        $m->aplicar($c, Transicion::ParametrosCompletos, ['nombre' => 'María', 'servicio' => 'corte']);
        $m->aplicar($c, Transicion::SlotElegido, ['horario' => '2026-08-24T12:00:00Z']);

        $ctx = $c->fresh()->context_data;

        $this->assertSame('María', $ctx['nombre'], 'Se perdio el nombre capturado en GATHERING_PARAMS.');
        $this->assertSame('corte', $ctx['servicio']);
        $this->assertSame('2026-08-24T12:00:00Z', $ctx['horario']);
    }

    /** Cada transición actualiza el momento de la última interacción. */
    public function test_actualiza_la_ultima_interaccion(): void
    {
        $c = $this->conversacion();
        $c->forceFill(['last_interaction_at' => now()->subHour()])->save();

        $this->maquina()->aplicar($c, Transicion::MensajeInicial);

        $this->assertTrue($c->fresh()->last_interaction_at->greaterThan(now()->subMinute()));
    }

    // ------------------------------------------------ AC-03.3 · aislamiento

    /** Dos clientes del mismo tenant avanzan independientes. */
    public function test_dos_clientes_del_mismo_tenant_no_se_pisan(): void
    {
        $a = $this->conversacion(tel: '5491111111111');
        $b = $this->conversacion(tel: '5492222222222');
        $m = $this->maquina();

        $m->aplicar($a, Transicion::MensajeInicial);
        $m->aplicar($a, Transicion::ParametrosCompletos);

        $this->assertSame('SELECTING_SLOT', $a->fresh()->current_state);
        $this->assertSame('IDLE', $b->fresh()->current_state, 'El avance de un cliente movio a otro.');
    }

    // ----------------------------------------------------- lock y ráfagas

    /** Dos mensajes en ráfaga no ejecutan la transición dos veces. */
    public function test_una_rafaga_no_aplica_la_transicion_dos_veces(): void
    {
        $c = $this->conversacion();
        $m = $this->maquina();

        // Se toma el lock por fuera, simulando al otro worker.
        $lockAjeno = Cache::lock("fsm:tenant:{$c->tenant_id}:phone:{$c->user_phone}", 10);
        $this->assertTrue($lockAjeno->get());

        try {
            $m->aplicar($c, Transicion::MensajeInicial);
            $this->fail('Se esperaba ConversacionOcupada.');
        } catch (ConversacionOcupada $e) {
            $this->assertSame($c->id, $e->conversacion->id);
        } finally {
            $lockAjeno->release();
        }

        $this->assertSame('IDLE', $c->fresh()->current_state);

        // Liberado el lock, la transicion se aplica normalmente.
        $this->assertSame(Estado::GatheringParams, $m->aplicar($c, Transicion::MensajeInicial));
    }

    /** El lock es por conversación, no por tenant: no frena a otro cliente. */
    public function test_el_lock_de_un_cliente_no_frena_a_otro(): void
    {
        $a = $this->conversacion(tel: '5491111111111');
        $b = $this->conversacion(tel: '5492222222222');

        $lockDeA = Cache::lock("fsm:tenant:{$a->tenant_id}:phone:{$a->user_phone}", 10);
        $lockDeA->get();

        try {
            $this->assertSame(Estado::GatheringParams,
                $this->maquina()->aplicar($b, Transicion::MensajeInicial));
        } finally {
            $lockDeA->release();
        }
    }

    // ------------------------------------------------- Redis vs MySQL

    /** MySQL es la fuente de verdad: la caché se invalida al transicionar. */
    public function test_la_cache_no_puede_contradecir_a_mysql(): void
    {
        $c = $this->conversacion();
        $m = $this->maquina();

        // Se calienta la cache con el estado viejo.
        $this->assertSame(Estado::Idle, $m->estadoDe($c));

        $m->aplicar($c, Transicion::MensajeInicial);

        $this->assertSame(Estado::GatheringParams, $m->estadoDe($c),
            'La cache siguio devolviendo el estado viejo despues de transicionar.');
        $this->assertSame('GATHERING_PARAMS', DB::table('conversations')->where('id', $c->id)->value('current_state'));
    }

    /** La clave de Redis lleva el tenant adentro (RNF-01). */
    public function test_la_clave_de_sesion_lleva_el_tenant(): void
    {
        $c = $this->conversacion();
        $this->maquina()->estadoDe($c);

        $this->assertTrue(Cache::has("session:tenant:{$c->tenant_id}:phone:{$c->user_phone}"));
    }

    /** Decidir la transición relee de MySQL, no de la caché. */
    public function test_decide_sobre_el_estado_de_mysql_y_no_sobre_la_cache(): void
    {
        $c = $this->conversacion();
        $m = $this->maquina();

        $m->estadoDe($c);   // calienta la cache en IDLE

        // Otro proceso mueve el estado por fuera, sin tocar la cache.
        DB::table('conversations')->where('id', $c->id)->update(['current_state' => 'SELECTING_SLOT']);

        // `MensajeInicial` es valido desde IDLE pero NO desde SELECTING_SLOT.
        $this->expectException(TransicionNoDeclarada::class);
        $m->aplicar($c, Transicion::MensajeInicial);
    }
}
