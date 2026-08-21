<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Conversacion\Interactivo\IdSellado;
use App\Conversacion\Interactivo\InterpreteDeRespuesta;
use App\Conversacion\Interactivo\LimitesDeMeta;
use App\Conversacion\Interactivo\Opcion;
use App\Conversacion\Interactivo\RenderizadorInteractivo;
use App\Conversacion\Interactivo\TipoRespuesta;
use App\Conversacion\MaquinaDeEstados;
use App\Conversacion\Transicion;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-019 · Mensajes interactivos de Meta.
 *
 * El criterio que no se recorta es **AC-03.2**: un botón de un mensaje anterior
 * no ejecuta la acción vieja.
 */
class MensajesInteractivosTest extends TestCase
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

    private function conversacion(Estado $estado = Estado::Idle): Conversation
    {
        return Conversation::create([
            'user_phone' => '5493764278402',
            'current_state' => $estado->value,
            'last_interaction_at' => now(),
        ]);
    }

    private function render(): RenderizadorInteractivo
    {
        return app(RenderizadorInteractivo::class);
    }

    /** @param array<string,mixed> $interactive */
    private function respuestaDeBoton(string $id): array
    {
        return ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']]];
    }

    // ------------------------------------------------- AC-03.2 · el sellado

    /**
     * El criterio central: un botón de un paso anterior **no** ejecuta su acción.
     */
    public function test_un_boton_de_un_paso_anterior_no_ejecuta_la_accion(): void
    {
        $c = $this->conversacion(Estado::GatheringParams);

        // El bot emite un boton estando en GATHERING_PARAMS.
        $mensaje = $this->render()->botones($c, '¿Qué servicio?', [new Opcion('corte', 'Corte')]);
        $idViejo = $mensaje['action']['buttons'][0]['reply']['id'];

        // La conversacion avanza a SELECTING_SLOT.
        app(MaquinaDeEstados::class)->aplicar($c, Transicion::ParametrosCompletos);

        // El cliente scrollea y toca el boton viejo.
        $r = app(InterpreteDeRespuesta::class)->interpretar($this->respuestaDeBoton($idViejo), $c->fresh());

        $this->assertSame(TipoRespuesta::Caduca, $r->tipo);
        $this->assertFalse($r->esAccionable(), 'Se ejecuto la accion de un boton viejo.');
        $this->assertNull($r->accion());
    }

    /** Un botón del paso actual sí se ejecuta. */
    public function test_un_boton_del_paso_actual_se_ejecuta(): void
    {
        $c = $this->conversacion(Estado::GatheringParams);

        $mensaje = $this->render()->botones($c, '¿Qué servicio?', [new Opcion('corte', 'Corte')]);
        $id = $mensaje['action']['buttons'][0]['reply']['id'];

        $r = app(InterpreteDeRespuesta::class)->interpretar($this->respuestaDeBoton($id), $c);

        $this->assertTrue($r->esAccionable());
        $this->assertSame('corte', $r->accion());
    }

    /**
     * El caso que el estado solo no cubre: **volver al mismo paso**.
     *
     * El bot ofrece horarios, la conversación reinicia y vuelve a ofrecer otros.
     * El estado coincide, pero el botón viejo apunta a una lista que ya no
     * existe — sin la versión, pasaría por válido.
     */
    public function test_un_boton_de_una_vuelta_anterior_al_mismo_paso_no_vale(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);

        $viejo = $this->render()->lista($c, 'Elegí', 'Ver horarios', [new Opcion('slot_1', '09:00')]);
        $idViejo = $viejo['action']['sections'][0]['rows'][0]['id'];

        // La conversacion expira y vuelve a llegar al mismo estado.
        $m = app(MaquinaDeEstados::class);
        $m->aplicar($c, Transicion::Inactividad);            // -> IDLE
        $m->aplicar($c, Transicion::MensajeInicial);         // -> GATHERING_PARAMS
        $m->aplicar($c, Transicion::ParametrosCompletos);    // -> SELECTING_SLOT

        $this->assertSame('SELECTING_SLOT', $c->fresh()->current_state, 'Precondicion: mismo estado.');

        $r = app(InterpreteDeRespuesta::class)->interpretar($this->respuestaDeBoton($idViejo), $c->fresh());

        $this->assertSame(TipoRespuesta::Caduca, $r->tipo,
            'Un boton de una lista vieja paso por valido porque el estado coincidia.');
    }

    /** La versión sube en cada transición. */
    public function test_la_version_sube_en_cada_transicion(): void
    {
        $c = $this->conversacion();
        $m = app(MaquinaDeEstados::class);

        $this->assertSame(0, (int) $c->state_version);

        $m->aplicar($c, Transicion::MensajeInicial);
        $this->assertSame(1, (int) $c->fresh()->state_version);

        $m->aplicar($c, Transicion::ParametrosCompletos);
        $this->assertSame(2, (int) $c->fresh()->state_version);
    }

    /** Un id que no emitimos nosotros no rompe nada. */
    public function test_un_id_ajeno_no_rompe_el_procesamiento(): void
    {
        $c = $this->conversacion();

        foreach (['confirmar', 'algo|raro', 'x|NO_EXISTE|1', 'accion|IDLE|noesnumero', ''] as $id) {
            $r = app(InterpreteDeRespuesta::class)->interpretar($this->respuestaDeBoton($id), $c);

            $this->assertFalse($r->esAccionable(), "El id '{$id}' se acciono.");
        }
    }

    /** Texto libre no se interpreta: el criterio lo prohíbe explícitamente. */
    public function test_el_texto_libre_no_se_resuelve_a_una_transicion(): void
    {
        $c = $this->conversacion();

        $r = app(InterpreteDeRespuesta::class)->interpretar(
            ['type' => 'text', 'text' => ['body' => 'quiero confirmar el turno']], $c
        );

        $this->assertSame(TipoRespuesta::NoInteractiva, $r->tipo);
        $this->assertFalse($r->esAccionable());
    }

    /** El id sellado entra en el campo de Meta. */
    public function test_el_id_sellado_entra_en_el_limite_de_meta(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);

        $sello = IdSellado::emitir('slot_2026_08_24_0930', $c);

        $this->assertLessThanOrEqual(IdSellado::MAX_LONGITUD, $sello->longitud());
    }

    // ------------------------------------- "nunca falla el envío"

    /** Más botones de los que Meta admite: se recortan, no se falla. */
    public function test_recorta_los_botones_que_exceden_el_limite(): void
    {
        $c = $this->conversacion();

        $opciones = array_map(fn ($i) => new Opcion("op_{$i}", "Opción {$i}"), range(1, 6));
        $mensaje = $this->render()->botones($c, 'Elegí', $opciones);

        $this->assertCount(LimitesDeMeta::MAX_BOTONES, $mensaje['action']['buttons']);
    }

    /** Un título largo se recorta sin cortar una palabra al medio. */
    public function test_recorta_los_textos_largos_de_forma_legible(): void
    {
        $c = $this->conversacion();

        $mensaje = $this->render()->botones($c, 'x', [
            new Opcion('largo', 'Confirmar mi turno para el martes'),
        ]);

        $titulo = $mensaje['action']['buttons'][0]['reply']['title'];

        $this->assertLessThanOrEqual(LimitesDeMeta::MAX_TEXTO_BOTON, mb_strlen($titulo));
        $this->assertStringEndsWith('…', $titulo);
        // No corta a mitad de palabra.
        $this->assertStringNotContainsString('mart…', $titulo);
    }

    /** Una lista larga se pagina, y cada página tiene salida. */
    public function test_pagina_una_lista_larga_y_deja_como_ver_mas(): void
    {
        $opciones = array_map(fn ($i) => new Opcion("slot_{$i}", "Horario {$i}"), range(1, 25));

        $paginas = $this->render()->paginar($opciones);

        $this->assertGreaterThan(1, count($paginas));

        foreach ($paginas as $i => $pagina) {
            $this->assertLessThanOrEqual(LimitesDeMeta::MAX_FILAS_LISTA, count($pagina),
                "La pagina {$i} excede el limite de filas de Meta.");

            if ($i < count($paginas) - 1) {
                $ultima = end($pagina);
                $this->assertStringStartsWith('ver_mas', $ultima->accion,
                    "La pagina {$i} no ofrece como ver las siguientes.");
            }
        }
    }

    /** Una lista corta no se pagina ni agrega "Ver más". */
    public function test_una_lista_corta_no_se_pagina(): void
    {
        $opciones = array_map(fn ($i) => new Opcion("slot_{$i}", "Horario {$i}"), range(1, 4));

        $paginas = $this->render()->paginar($opciones);

        $this->assertCount(1, $paginas);
        $this->assertCount(4, $paginas[0]);
    }

    /** El cuerpo del mensaje también se recorta. */
    public function test_recorta_el_cuerpo_del_mensaje(): void
    {
        $c = $this->conversacion();

        $mensaje = $this->render()->botones($c, str_repeat('texto larguísimo ', 200), [
            new Opcion('ok', 'Dale'),
        ]);

        $this->assertLessThanOrEqual(LimitesDeMeta::MAX_CUERPO, mb_strlen($mensaje['body']['text']));
    }

    /** La estructura es la que espera el endpoint de Meta. */
    public function test_la_lista_tiene_la_forma_que_espera_meta(): void
    {
        $c = $this->conversacion(Estado::SelectingSlot);

        $m = $this->render()->lista($c, 'Elegí un horario', 'Ver horarios', [
            new Opcion('slot_1', '09:00', 'Lunes 24 de agosto'),
        ], 'Lunes');

        $this->assertSame('list', $m['type']);
        $this->assertSame('Ver horarios', $m['action']['button']);
        $this->assertSame('Lunes', $m['action']['sections'][0]['title']);

        $fila = $m['action']['sections'][0]['rows'][0];
        $this->assertSame('09:00', $fila['title']);
        $this->assertSame('Lunes 24 de agosto', $fila['description']);
        $this->assertStringContainsString('SELECTING_SLOT', $fila['id']);
    }
}
