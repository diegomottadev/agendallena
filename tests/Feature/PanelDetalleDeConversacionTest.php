<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Conversacion\Pausa;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-049 · Panel: detalle de conversación con historial.
 *
 * ## Por qué importa
 *
 * Es el ticket que responde al caso de uso original de US-14: *"recibo un
 * reclamo de un cliente y quiero resolverlo yo en dos minutos en lugar de
 * escribirle al proveedor y esperar"*. Sin el historial, cada reclamo escala a
 * soporte, que es el costo que el modelo de tarifa plana no aguanta.
 *
 * Y es el otro lugar donde el aislamiento del RNF-01 se rompe barato: una URL
 * con el id de otra PyME expone **la conversación entera** de un cliente que no
 * es nuestro usuario y no aceptó nada con nosotros.
 *
 * ⚠️ **La URL del detalle la fija este ticket:** `/panel/conversaciones/{id}`,
 * por debajo del listado que ya existe. Ningún documento la nombra.
 */
class PanelDetalleDeConversacionTest extends TestCase
{
    use RefreshDatabase;

    private const LISTADO = '/panel/conversaciones';

    private const TZ = 'America/Argentina/Buenos_Aires';

    /** En Buenos Aires cae el día anterior: es el trampolín del test de RNF-02. */
    private const INICIO_UTC = '2026-08-25 01:30:00';

    private const DIA_LOCAL = '24/08/2026';

    private const DIA_UTC = '25/08/2026';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', self::TZ));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ andamiaje

    private function url(Conversation $c): string
    {
        return self::LISTADO.'/'.$c->id;
    }

    private function tenant(string $slug = 'piloto'): Tenant
    {
        return Tenant::create([
            'name' => 'Peluquería '.$slug, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);
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
        string $telefono = '5493764278402',
        ?string $nombre = 'Marisa Duarte',
        Estado $estado = Estado::Booked,
    ): Conversation {
        return TenantContext::runAs($tenant->id, fn () => Conversation::create([
            'user_phone' => $telefono,
            'user_name' => $nombre,
            'current_state' => $estado->value,
            'last_interaction_at' => CarbonImmutable::parse('2026-08-22 12:00:00', 'UTC'),
        ]));
    }

    /** @var array<string,Integration> Una sola por tenant: `(provider, account_identifier)` es único. */
    private array $integraciones = [];

    private function turno(
        Tenant $tenant,
        Conversation $conversacion,
        string $eventId,
        ?string $monto = null,
    ): Booking {
        $integracion = $this->integraciones[(string) $tenant->id] ??= Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'agenda@'.$tenant->slug.'.com.ar',
            'access_token' => 'ya29.token',
            'refresh_token' => '1//refresh',
            'status' => 'connected',
        ]);

        return TenantContext::runAs($tenant->id, fn () => Booking::create([
            'conversation_id' => $conversacion->id,
            'integration_id' => $integracion->id,
            'external_event_id' => $eventId,
            'client_name' => $conversacion->user_name ?? 'Cliente',
            'client_phone' => $conversacion->user_phone,
            'service_name' => 'Corte y barba',
            'quote_amount' => $monto,
            'start_time' => CarbonImmutable::parse(self::INICIO_UTC, 'UTC'),
            'end_time' => CarbonImmutable::parse(self::INICIO_UTC, 'UTC')->addMinutes(30),
            'status' => Booking::ESTADO_AGENDADO,
        ]));
    }

    /** Un mensaje del historial, con la hora que le toca. */
    private function mensaje(
        Tenant $tenant,
        Conversation $conversacion,
        string $direccion,
        string $texto,
        string $cuandoUtc,
    ): void {
        DB::table('messages')->insert([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversacion->id,
            'direction' => $direccion,
            'type' => 'text',
            'content' => $texto,
            'payload' => null,
            'whatsapp_message_id' => 'wamid.'.uniqid('', true),
            'created_at' => $cuandoUtc,
            'updated_at' => $cuandoUtc,
        ]);
    }

    /** Historial largo, para el test de paginado. */
    private function sembrarMensajes(Tenant $tenant, Conversation $conversacion, int $cantidad): void
    {
        $base = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');

        foreach (array_chunk(range(1, $cantidad), 200) as $lote) {
            DB::table('messages')->insert(array_map(fn (int $n) => [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversacion->id,
                'direction' => $n % 2 === 0 ? Message::SALIENTE : Message::ENTRANTE,
                'type' => 'text',
                'content' => 'MSG-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'payload' => null,
                'whatsapp_message_id' => 'wamid.seed.'.$n,
                'created_at' => $base->addMinutes($n)->format('Y-m-d H:i:s'),
                'updated_at' => $base->addMinutes($n)->format('Y-m-d H:i:s'),
            ], $lote));
        }
    }

    /** @return array<int,string> */
    private function marcadoresVisibles(string $html, int $cantidad): array
    {
        return array_values(array_filter(
            array_map(fn (int $n) => 'MSG-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), range(1, $cantidad)),
            fn (string $m) => str_contains($html, $m),
        ));
    }

    /**
     * ¿Este enlace apunta a **ese** evento?
     *
     * ⚠️ Acepta el id crudo o el `eid` de Google —`base64(id + " " + calendario)`—
     * porque nadie decidió cuál: no guardamos el `htmlLink` de la API.
     *
     * @return array<int,string>
     */
    private function enlacesAlCalendario(string $html): array
    {
        preg_match_all('/href="([^"]*)"/i', $html, $m);

        return array_values(array_filter(
            array_map(fn (string $u) => html_entity_decode($u, ENT_QUOTES), $m[1]),
            fn (string $u) => str_contains($u, 'calendar.google.com') || str_contains($u, 'google.com/calendar'),
        ));
    }

    private function identificaAlEvento(string $url, string $eventId): bool
    {
        $url = urldecode($url);

        if (str_contains($url, $eventId)) {
            return true;
        }

        foreach (preg_split('/[?&=\/#]/', $url) ?: [] as $trozo) {
            if (strlen($trozo) < 8) {
                continue;
            }

            $crudo = base64_decode(strtr($trozo, '-_', '+/'), false);

            if (is_string($crudo) && str_contains($crudo, $eventId)) {
                return true;
            }
        }

        return false;
    }

    // ----------------------------------------------- AC-14.4 · el historial

    /**
     * AC-14.4 · El historial completo, entrante y saliente, **en orden**.
     *
     * Es el ticket entero: sin las dos direcciones, el dueño ve lo que preguntó
     * el cliente y no lo que le contestó el bot, que es justo lo que necesita
     * para resolver un reclamo sin llamarnos.
     */
    public function test_el_detalle_muestra_el_historial_completo_entrante_y_saliente_en_orden(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $c = $this->conversacion($tenant);

        $this->mensaje($tenant, $c, Message::ENTRANTE, 'Hola, quiero un turno', '2026-08-24 12:00:00');
        $this->mensaje($tenant, $c, Message::SALIENTE, 'Dale, ¿para qué día?', '2026-08-24 12:00:30');
        $this->mensaje($tenant, $c, Message::ENTRANTE, 'El martes a la tarde', '2026-08-24 12:01:00');
        $this->mensaje($tenant, $c, Message::SALIENTE, 'Te queda reservado', '2026-08-24 12:01:30');

        $html = $this->actingAs($usuario)->get($this->url($c))->assertOk()->getContent();

        foreach (['Hola, quiero un turno', 'Dale, ¿para qué día?', 'El martes a la tarde', 'Te queda reservado'] as $texto) {
            $this->assertStringContainsString(e($texto), $html,
                "El historial no muestra el mensaje «{$texto}»: falta una de las dos direcciones (AC-14.4).");
        }

        $posiciones = array_map(
            fn (string $t) => strpos($html, e($t)),
            ['Hola, quiero un turno', 'Dale, ¿para qué día?', 'El martes a la tarde', 'Te queda reservado'],
        );

        $ordenado = $posiciones;
        sort($ordenado);

        $this->assertSame($ordenado, $posiciones,
            'El historial no está en orden cronológico: leído así, la conversación no se entiende.');
    }

    /** Entrante y saliente se distinguen: quién dijo qué es la mitad del historial. */
    public function test_el_historial_distingue_lo_que_dijo_el_cliente_de_lo_que_contesto_el_bot(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $c = $this->conversacion($tenant, nombre: 'Marisa Duarte');

        $this->mensaje($tenant, $c, Message::ENTRANTE, 'Quiero un turno', '2026-08-24 12:00:00');
        $this->mensaje($tenant, $c, Message::SALIENTE, 'Listo, quedó reservado', '2026-08-24 12:00:30');

        $html = $this->actingAs($usuario)->get($this->url($c))->assertOk()->getContent();

        $entrante = substr($html, (int) strpos($html, 'Quiero un turno') - 400, 400);
        $saliente = substr($html, (int) strpos($html, 'Listo, quedó reservado') - 400, 400);

        $this->assertNotSame($entrante, $saliente,
            'Un mensaje del cliente y uno del bot se dibujan igual: no se sabe quién dijo qué (AC-14.4).');
    }

    /** Sin mensajes no hay error: el historial vacío es un estado normal. */
    public function test_una_conversacion_sin_mensajes_abre_igual(): void
    {
        $tenant = $this->tenant();
        $c = $this->conversacion($tenant, nombre: 'Recién llegada');

        $this->actingAs($this->usuario($tenant))->get($this->url($c))
            ->assertOk()
            ->assertSee('Recién llegada');
    }

    // ------------------------- AC-14.3 · estado, turno con enlace, y el monto

    /**
     * AC-14.3 · El detalle también tiene que responder *"¿en qué quedó?"*: en
     * qué estado está, cuándo es el turno y cuánto se cotizó, sin volver al
     * listado.
     */
    public function test_el_detalle_muestra_el_estado_el_turno_con_enlace_al_calendario_y_el_monto(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $c = $this->conversacion($tenant, nombre: 'Marisa Duarte');
        $evento = 'evtdetalle1111aaaa2222';
        $this->turno($tenant, $c, $evento, monto: '4500.00');

        $html = $this->actingAs($usuario)->get($this->url($c))->assertOk()->getContent();

        $this->assertStringContainsString('Turno agendado', $html,
            'El detalle no dice en qué estado está la conversación.');

        // RNF-02 · UTC en la base, hora del negocio en la pantalla.
        $this->assertStringContainsString(self::DIA_LOCAL, $html,
            'La fecha del turno no aparece, o no está en la zona del negocio.');
        $this->assertStringNotContainsString(self::DIA_UTC, $html,
            'Se imprimió la fecha cruda de la base (UTC): el turno se lee un día corrido.');

        $this->assertMatchesRegularExpression('/4[.,\s]?500/', $html,
            'El monto cotizado no aparece en el detalle (AC-14.3).');

        $enlaces = $this->enlacesAlCalendario($html);
        $apunta = array_filter($enlaces, fn (string $u) => $this->identificaAlEvento($u, $evento));

        $this->assertNotEmpty($apunta,
            "El detalle no ofrece un enlace que abra el evento {$evento} en Google Calendar (AC-14.3). "
            .'Enlaces encontrados: '.implode(' | ', $enlaces));
    }

    /**
     * ⚠️ El cotizador está congelado: `quote_amount` es `null` en todo el
     * sistema. El detalle tiene que aguantarlo sin romper y sin afirmar que se
     * cotizó cero.
     */
    public function test_un_turno_sin_monto_no_rompe_el_detalle_ni_se_muestra_como_cero(): void
    {
        $tenant = $this->tenant();
        $c = $this->conversacion($tenant, nombre: 'Sin cotizar');
        $this->turno($tenant, $c, 'evtsincotizar9999', monto: null);
        $this->mensaje($tenant, $c, Message::ENTRANTE, 'Quiero un turno', '2026-08-24 12:00:00');

        $html = $this->actingAs($this->usuario($tenant))->get($this->url($c))->assertOk()->getContent();

        $this->assertStringContainsString('Quiero un turno', $html,
            'Un turno sin monto se llevó puesto el historial entero.');
        $this->assertDoesNotMatchRegularExpression('/\b0[.,]00\b/', $html,
            'Un turno sin cotizar se muestra como cotizado en cero.');
    }

    // -------------------------------------- AC-21.4 · la marca de la pausa

    /**
     * AC-21.4 · Una pausa **manual** significa *"un compañero está atendiendo a
     * este cliente"*. Escribirle encima es el peor error posible en esta
     * pantalla, así que la marca va junto al historial.
     */
    public function test_una_pausa_manual_se_muestra_junto_al_historial_con_su_responsable(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant, nombre: 'Vanina Gómez');
        $c = $this->conversacion($tenant);
        $this->mensaje($tenant, $c, Message::ENTRANTE, 'Quiero un turno', '2026-08-24 12:00:00');

        TenantContext::runAs($tenant->id, fn () => Pausa::activar($c, Pausa::ORIGEN_MANUAL, (string) $usuario->id));

        $this->actingAs($usuario)->get($this->url($c))
            ->assertOk()
            ->assertSee('Pausado desde el panel')
            ->assertSee('Lo pausó Vanina Gómez', false)
            ->assertSee('Quiero un turno');
    }

    /**
     * AC-21.4 · Y una **detectada** significa lo contrario: nadie apretó nada,
     * el sistema dedujo que alguien contestó por WhatsApp. Leerlas igual lleva a
     * confiar en una deducción como si fuera una decisión de una persona.
     */
    public function test_una_pausa_detectada_se_distingue_de_una_manual(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant, nombre: 'Vanina Gómez');
        $c = $this->conversacion($tenant);

        TenantContext::runAs($tenant->id, fn () => Pausa::activar($c, Pausa::ORIGEN_AUTOMATICO));

        $this->actingAs($usuario)->get($this->url($c))
            ->assertOk()
            ->assertSee('Pausado automáticamente', false)
            ->assertDontSee('Pausado desde el panel')
            ->assertDontSee('Lo pausó', false);
    }

    /** Sin pausa, ninguna marca: una marca fantasma frena a quien podía seguir. */
    public function test_una_conversacion_sin_pausa_no_muestra_ninguna_marca(): void
    {
        $tenant = $this->tenant();
        $c = $this->conversacion($tenant);

        $this->actingAs($this->usuario($tenant))->get($this->url($c))
            ->assertOk()
            ->assertDontSee('Pausado desde el panel')
            ->assertDontSee('Pausado automáticamente', false);
    }

    // ------------------------------------------------- AC-14.2 · el 403

    /**
     * AC-14.2 · RNF-01 · Abrir por URL directa una conversación de otra PyME
     * devuelve **403**.
     *
     * Es el incidente que termina un contrato: acá no se filtra un nombre, se
     * filtra la conversación entera de alguien que no es usuario nuestro.
     *
     * ⚠️ El `403` y no `404` es deliberado, y `ConversacionesController` ya fijó
     * el precedente: con el Global Scope puesto la fila ajena no aparece y sale
     * un `404` indistinguible de un id inexistente. El criterio pide `403`.
     *
     * ⚠️ La comparación de tenants va **como string**: `tenant_id` es UUID y un
     * `(int)` sobre un UUID devuelve `1` para todos — este test pasaría con la
     * fuga presente.
     */
    public function test_abrir_una_conversacion_de_otro_tenant_devuelve_403(): void
    {
        $mio = $this->tenant('mio');
        $ajeno = $this->tenant('ajeno');

        $this->assertNotSame((string) $mio->id, (string) $ajeno->id);

        $usuario = $this->usuario($mio);
        $propia = $this->conversacion($mio, '5493764111111', nombre: 'Cliente propio');
        $deOtro = $this->conversacion($ajeno, '5493764999999', nombre: 'Cliente ajeno');
        $this->mensaje($ajeno, $deOtro, Message::ENTRANTE, 'Datos privados de otra PyME', '2026-08-24 12:00:00');

        // Canario: si el detalle propio no abre, el 403 de abajo no prueba nada
        // sobre el aislamiento — podría ser la ruta rota.
        $this->actingAs($usuario)->get($this->url($propia))->assertOk();

        $respuesta = $this->actingAs($usuario)->get($this->url($deOtro));

        $respuesta->assertForbidden();

        $this->assertStringNotContainsString('Datos privados de otra PyME', $respuesta->getContent(),
            'La respuesta denegada igual filtró el contenido de la conversación ajena (RNF-01).');
        $this->assertStringNotContainsString('5493764999999', $respuesta->getContent(),
            'La respuesta denegada igual filtró el teléfono del cliente ajeno (RNF-01).');
    }

    /**
     * AC-14.2 · Y el intento **queda registrado**.
     *
     * Un acceso cruzado que no deja rastro no se puede auditar, y la auditoría
     * de RNF-01 es lo que sostiene la promesa frente al cliente.
     */
    public function test_el_intento_de_abrir_una_conversacion_ajena_queda_registrado(): void
    {
        $mio = $this->tenant('mio');
        $ajeno = $this->tenant('ajeno');
        $usuario = $this->usuario($mio);
        $deOtro = $this->conversacion($ajeno, '5493764999999');

        Log::spy();

        $this->actingAs($usuario)->get($this->url($deOtro))->assertForbidden();

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // ------------------------------------- el historial pagina hacia atrás

    /**
     * *"El historial pagina hacia atrás sin cargar la conversación entera de una
     * vez."*
     *
     * `messages` es la tabla de mayor crecimiento del sistema: un cliente de
     * meses tiene cientos de mensajes y traerlos todos para dibujar la pantalla
     * es lo que este criterio prohíbe.
     *
     * ⚠️ Se asume el paginado estándar de Laravel (`?page=`). El ticket no dice
     * si es paginado o cursor; lo que el test afirma es el **comportamiento**:
     * la primera pantalla trae un puñado y hay forma de ir hacia atrás.
     */
    public function test_el_historial_no_carga_la_conversacion_entera_de_una_vez(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $c = $this->conversacion($tenant);
        $this->sembrarMensajes($tenant, $c, 300);

        $html = $this->actingAs($usuario)->get($this->url($c))->assertOk()->getContent();

        $visibles = $this->marcadoresVisibles($html, 300);

        $this->assertNotEmpty($visibles,
            'El detalle no mostró ningún mensaje con 300 cargados.');
        $this->assertLessThan(100, count($visibles),
            'El detalle trajo '.count($visibles).' mensajes de 300: se carga la conversación entera '
            .'y se recorta en la vista.');
    }

    /** Y hacia atrás están los viejos: los que la primera pantalla no trajo. */
    public function test_el_historial_pagina_hacia_atras_y_trae_los_mensajes_mas_viejos(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);
        $c = $this->conversacion($tenant);
        $this->sembrarMensajes($tenant, $c, 120);

        $primera = $this->marcadoresVisibles(
            $this->actingAs($usuario)->get($this->url($c))->assertOk()->getContent(), 120
        );
        $segunda = $this->marcadoresVisibles(
            $this->actingAs($usuario)->get($this->url($c).'?page=2')->assertOk()->getContent(), 120
        );

        $this->assertNotEmpty($segunda,
            'La segunda página del historial vino vacía con 120 mensajes: no se puede ir hacia atrás.');
        $this->assertEmpty(array_intersect($primera, $segunda),
            'Hay mensajes repetidos entre la página 1 y la 2 del historial: el orden del paginado no es estable.');

        /*
         * "Hacia atrás" es una dirección, no solo una segunda página: lo último
         * que se dijo es lo que resuelve el reclamo, así que abre en el final de
         * la conversación y desde ahí se va al pasado.
         */
        $this->assertContains('MSG-0120', $primera,
            'La primera pantalla no trae el último mensaje: el historial abre en el principio de la '
            .'conversación y hay que paginar para llegar a lo que pasó hoy.');
        $this->assertNotContains('MSG-0001', $primera,
            'La primera pantalla trae el mensaje más viejo: el historial no pagina hacia atrás.');
    }

    /**
     * El historial de una conversación no puede traer mensajes de otra.
     *
     * Dos clientes del mismo negocio: el modo de falla es filtrar por
     * `tenant_id` y olvidarse del `conversation_id`, y el índice de T-048 lleva
     * las dos columnas justamente porque la consulta las necesita a las dos.
     */
    public function test_el_historial_no_mezcla_mensajes_de_otra_conversacion(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $una = $this->conversacion($tenant, '5493764111111', nombre: 'Marisa');
        $otra = $this->conversacion($tenant, '5493764222222', nombre: 'Rubén');

        $this->mensaje($tenant, $una, Message::ENTRANTE, 'Lo que dijo Marisa', '2026-08-24 12:00:00');
        $this->mensaje($tenant, $otra, Message::ENTRANTE, 'Lo que dijo Rubén', '2026-08-24 12:00:10');

        $html = $this->actingAs($usuario)->get($this->url($una))->assertOk()->getContent();

        $this->assertStringContainsString('Lo que dijo Marisa', $html,
            'El historial de la conversación abierta no muestra su propio mensaje.');
        $this->assertStringNotContainsString(e('Lo que dijo Rubén'), $html,
            'El detalle mezcló el mensaje de otro cliente del mismo negocio.');
    }

    // ------------------------------------------------------- autorización

    /** Atender es de los tres roles: el staff que atiende el reclamo lo necesita. */
    public function test_un_staff_puede_abrir_el_detalle(): void
    {
        $tenant = $this->tenant();
        $c = $this->conversacion($tenant, nombre: 'Marisa Duarte');

        $this->actingAs($this->usuario($tenant, Role::Staff))->get($this->url($c))
            ->assertOk()
            ->assertSee('Marisa Duarte');
    }

    /** Sin sesión no hay detalle: es una conversación de un tercero. */
    public function test_sin_login_no_hay_detalle(): void
    {
        $tenant = $this->tenant();
        $c = $this->conversacion($tenant);

        $this->get($this->url($c))->assertRedirect('/login');
    }
}
