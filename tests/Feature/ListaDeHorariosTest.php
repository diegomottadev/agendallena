<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Conversacion\Interactivo\IdSellado;
use App\Conversacion\Interactivo\LimitesDeMeta;
use App\Conversacion\ListaDeHorarios;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-023 · Lista interactiva de horarios con paginado.
 *
 * El riesgo que marca el ticket: *"la identificación del slot tiene que incluir
 * la fecha completa en UTC: si solo lleva la hora, dos días distintos colisionan"*.
 */
class ListaDeHorariosTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    private Conversation $conversacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => self::TZ,
        ]);
        TenantContext::set($this->tenant->id);

        $this->conversacion = Conversation::create([
            'user_phone' => '5493764278402',
            'current_state' => Estado::SelectingSlot->value,
            'last_interaction_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function lista(): ListaDeHorarios
    {
        return app(ListaDeHorarios::class);
    }

    /** @return array<int,CarbonImmutable> */
    private function horarios(int $cantidad, string $desde = '2026-08-24 09:00'): array
    {
        $base = CarbonImmutable::parse($desde, self::TZ);

        return array_map(fn ($i) => $base->addHours($i), range(0, $cantidad - 1));
    }

    /** @return array<int,array<string,mixed>> */
    private function filasDe(array $mensaje): array
    {
        return $mensaje['action']['sections'][0]['rows'];
    }

    // ------------------------------------------- el riesgo del ticket

    /**
     * Dos días a la misma hora producen `id` **distintos**.
     *
     * Con `slot_0900`, el martes a las 9 y el miércoles a las 9 colisionan: el
     * cliente elige uno y se le agenda el otro.
     */
    public function test_el_mismo_horario_en_dos_dias_no_colisiona(): void
    {
        $martes = CarbonImmutable::parse('2026-08-25 09:00', self::TZ);
        $miercoles = CarbonImmutable::parse('2026-08-26 09:00', self::TZ);

        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, [$martes, $miercoles]);
        $filas = $this->filasDe($mensaje);

        $this->assertNotSame($filas[0]['id'], $filas[1]['id'],
            'Dos dias distintos a la misma hora produjeron el mismo id.');
    }

    /** El `id` resuelve al instante exacto, sin ambigüedad. */
    public function test_seleccionar_un_item_resuelve_al_instante_exacto(): void
    {
        $esperado = CarbonImmutable::parse('2026-08-26 15:30', self::TZ);

        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, [$esperado]);
        $id = $this->filasDe($mensaje)[0]['id'];

        // Se lee como lo leeria el interprete: primero el sello, despues la accion.
        $sello = IdSellado::leer($id);
        $this->assertNotNull($sello);

        $recuperado = ListaDeHorarios::horarioDe($sello->accion);

        $this->assertNotNull($recuperado);
        $this->assertTrue($esperado->equalTo($recuperado),
            "Se recupero {$recuperado->toIso8601String()} en vez de {$esperado->toIso8601String()}.");
    }

    /** El instante viaja en UTC dentro del id (RNF-02). */
    public function test_el_id_lleva_el_instante_en_utc(): void
    {
        $local = CarbonImmutable::parse('2026-08-26 15:30', self::TZ);

        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, [$local]);
        $sello = IdSellado::leer($this->filasDe($mensaje)[0]['id']);

        $recuperado = ListaDeHorarios::horarioDe($sello->accion);

        $this->assertSame('UTC', $recuperado->timezone->getName());
        $this->assertSame('18:30', $recuperado->format('H:i'), 'No se guardo el equivalente UTC.');
    }

    // ------------------------------------------- AC-05.4 · el límite de Meta

    /** Más horarios que el límite: se muestran los primeros y se ofrece ver más. */
    public function test_con_mas_horarios_que_el_limite_ofrece_ver_mas(): void
    {
        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, $this->horarios(25));
        $filas = $this->filasDe($mensaje);

        $this->assertLessThanOrEqual(LimitesDeMeta::MAX_FILAS_LISTA, count($filas),
            'El mensaje excede el limite de Meta: fallaria el envio entero.');

        $ultima = end($filas);
        $sello = IdSellado::leer($ultima['id']);
        $this->assertNotNull(ListaDeHorarios::paginaSolicitada($sello->accion),
            'No hay forma de ver los horarios siguientes.');
    }

    /** Con pocos horarios no aparece "ver más". */
    public function test_con_pocos_horarios_no_ofrece_ver_mas(): void
    {
        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, $this->horarios(4));
        $filas = $this->filasDe($mensaje);

        $this->assertCount(4, $filas);

        foreach ($filas as $fila) {
            $sello = IdSellado::leer($fila['id']);
            $this->assertNull(ListaDeHorarios::paginaSolicitada($sello->accion));
        }
    }

    /** La segunda página trae horarios distintos de la primera. */
    public function test_la_segunda_pagina_trae_horarios_distintos(): void
    {
        $horarios = $this->horarios(25);

        $primera = $this->filasDe($this->lista()->mensaje($this->conversacion, $this->tenant, $horarios, 0));
        $segunda = $this->filasDe($this->lista()->mensaje($this->conversacion, $this->tenant, $horarios, 1));

        $idsPrimera = array_column($primera, 'id');
        $idsSegunda = array_column($segunda, 'id');

        // Solo puede repetirse la fila de "ver mas", que no es un horario.
        $repetidos = array_intersect($idsPrimera, $idsSegunda);
        $this->assertEmpty($repetidos, 'La segunda pagina repite horarios de la primera.');
    }

    /** Pedir una página que no existe devuelve null en vez de romper. */
    public function test_una_pagina_inexistente_devuelve_null(): void
    {
        $this->assertNull(
            $this->lista()->mensaje($this->conversacion, $this->tenant, $this->horarios(4), 5)
        );
    }

    // --------------------------------------------- zona horaria y legibilidad

    /** Los horarios se muestran en la zona del tenant, no en UTC. */
    public function test_muestra_los_horarios_en_la_zona_del_tenant(): void
    {
        $local = CarbonImmutable::parse('2026-08-26 15:30', self::TZ);

        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, [$local]);
        $fila = $this->filasDe($mensaje)[0];

        $this->assertStringContainsString('15:30', $fila['title']);
        $this->assertStringNotContainsString('18:30', $fila['title'], 'Se mostro la hora UTC al cliente.');
    }

    /** Cada fila dice de qué día es. */
    public function test_cada_horario_dice_de_que_dia_es(): void
    {
        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, [
            CarbonImmutable::parse('2026-08-26 09:00', self::TZ),
        ]);

        $fila = $this->filasDe($mensaje)[0];

        $this->assertArrayHasKey('description', $fila);
        $this->assertStringContainsString('miércoles', $fila['description']);
        $this->assertStringContainsString('agosto', $fila['description']);
    }

    /** Si todos son del mismo día, el título de la sección lo dice. */
    public function test_el_titulo_de_seccion_nombra_el_dia_cuando_hay_uno_solo(): void
    {
        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, [
            CarbonImmutable::parse('2026-08-26 09:00', self::TZ),
            CarbonImmutable::parse('2026-08-26 10:00', self::TZ),
        ]);

        $this->assertStringContainsString('miércoles', $mensaje['action']['sections'][0]['title']);
    }

    /** Con varios días, el título no miente sobre uno solo. */
    public function test_el_titulo_es_generico_cuando_hay_varios_dias(): void
    {
        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, [
            CarbonImmutable::parse('2026-08-26 09:00', self::TZ),
            CarbonImmutable::parse('2026-08-27 09:00', self::TZ),
        ]);

        $this->assertSame('Horarios disponibles', $mensaje['action']['sections'][0]['title']);
    }

    // --------------------------------------------------------- forma y límites

    /** Todos los textos entran en los límites de Meta. */
    public function test_todos_los_textos_entran_en_los_limites(): void
    {
        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, $this->horarios(25));

        $this->assertLessThanOrEqual(LimitesDeMeta::MAX_TEXTO_BOTON_LISTA, mb_strlen($mensaje['action']['button']));
        $this->assertLessThanOrEqual(LimitesDeMeta::MAX_TITULO_SECCION, mb_strlen($mensaje['action']['sections'][0]['title']));

        foreach ($this->filasDe($mensaje) as $fila) {
            $this->assertLessThanOrEqual(LimitesDeMeta::MAX_TITULO_FILA, mb_strlen($fila['title']));
            $this->assertLessThanOrEqual(IdSellado::MAX_LONGITUD, strlen($fila['id']));

            if (isset($fila['description'])) {
                $this->assertLessThanOrEqual(LimitesDeMeta::MAX_DESCRIPCION_FILA, mb_strlen($fila['description']));
            }
        }
    }

    /** El id lleva el sello del estado emisor (AC-03.2). */
    public function test_los_items_llevan_el_sello_del_estado(): void
    {
        $mensaje = $this->lista()->mensaje($this->conversacion, $this->tenant, $this->horarios(3));

        foreach ($this->filasDe($mensaje) as $fila) {
            $sello = IdSellado::leer($fila['id']);

            $this->assertNotNull($sello);
            $this->assertSame(Estado::SelectingSlot, $sello->estadoEmisor);
        }
    }

    /** "Ver más" no se confunde con un horario. */
    public function test_ver_mas_no_se_lee_como_un_horario(): void
    {
        $this->assertNull(ListaDeHorarios::horarioDe('slot_pagina_1'));
        $this->assertNotNull(ListaDeHorarios::paginaSolicitada('slot_pagina_1'));

        $this->assertNotNull(ListaDeHorarios::horarioDe('slot_1787572800'));
        $this->assertNull(ListaDeHorarios::paginaSolicitada('slot_1787572800'));
    }
}
