<?php

namespace Tests\Feature;

use App\Conversacion\Derivacion;
use App\Conversacion\Estado;
use App\Conversacion\MotivoDeDerivacion;
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
 * T-035 · Derivación a una persona (US-20).
 *
 * Lo que se prueba acá es que **la promesa se cumpla de los dos lados**: que el
 * cliente al que el bot le dijo "te va a atender una persona" deje de recibir
 * respuestas automáticas, y que del lado de la PyME alguien se entere.
 *
 * Los dos orígenes que existen hoy tienen su recorrido completo: la agenda llena
 * de T-024 y las entradas que el bot no entiende. El tercero —valor fuera de
 * rango— **no se prueba porque no existe**: el cotizador está diferido.
 */
class DerivacionAUnaPersonaTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ andamiaje

    /**
     * ⚠️ `Http::fake()` acumula stubs: se llama **una sola vez por test**. Con
     * dos llamadas, la segunda no reemplaza a la primera y el test pasaría por
     * la razón equivocada.
     *
     * @param  bool  $agendaLlena  Toda la ventana ocupada en Google.
     */
    private function fakes(bool $agendaLlena = true): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => $agendaLlena
                    ? [['start' => '2026-08-24T00:00:00-03:00', 'end' => '2026-09-30T00:00:00-03:00']]
                    : [],
                ]],
            ]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT_'.uniqid()]]]),
        ]);
    }

    private function tenant(string $slug = 'piloto', bool $conIntegraciones = true): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Peluquería '.$slug, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        if ($conIntegraciones) {
            Integration::create([
                'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
                'account_identifier' => self::PHONE_NUMBER_ID, 'access_token' => 'meta',
                'settings' => ['verify_token' => 'tok'], 'status' => 'connected',
            ]);

            Integration::create([
                'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
                'account_identifier' => 'duenio@peluqueria.com', 'access_token' => 'ya29.token',
                'refresh_token' => '1//refresh',
                'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
                'status' => 'connected',
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
        return TenantContext::runAs($tenant->id, fn () => Conversation::create([
            'user_phone' => $telefono,
            'user_name' => $nombre,
            'current_state' => $estado->value,
            'last_interaction_at' => now(),
        ]));
    }

    /** @return array<string,mixed> */
    private function payload(array $mensaje): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [$mensaje]],
        ]]]]];
    }

    private function escribe(string $cuerpo, string $de = self::TELEFONO): void
    {
        (new ProcessMessageJob($this->payload([
            'from' => $de, 'id' => 'wamid.'.uniqid(), 'type' => 'text', 'text' => ['body' => $cuerpo],
        ])))->handle();
    }

    private function toca(string $idBoton, string $de = self::TELEFONO): void
    {
        (new ProcessMessageJob($this->payload([
            'from' => $de, 'id' => 'wamid.'.uniqid(), 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $idBoton, 'title' => 'x']],
        ])))->handle();
    }

    /** Los cuerpos que se le mandaron a Meta, en orden. */
    private function salientes(): array
    {
        $out = [];

        foreach (Http::recorded() as [$request, $response]) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                $out[] = $request->data();
            }
        }

        return $out;
    }

    /** Cuántas veces salió el mensaje de derivación. */
    private function vecesQueSeAviso(): int
    {
        $n = 0;

        foreach ($this->salientes() as $m) {
            if (($m['text']['body'] ?? null) === Derivacion::MENSAJE_SIN_NUMERO) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Recorre el flujo hasta el mensaje de agenda llena y devuelve el `id` del
     * botón «Hablar con una persona».
     */
    private function hastaLaAgendaLlena(): string
    {
        $this->escribe('Hola');

        $bienvenida = $this->salientes()[0];
        $this->toca($bienvenida['interactive']['action']['buttons'][0]['reply']['id']);

        $this->escribe('María');

        $salientes = $this->salientes();
        $ultimo = end($salientes) ?: [];
        $botones = $ultimo['interactive']['action']['buttons'] ?? [];

        $this->assertNotEmpty($botones,
            'Con la agenda llena el cliente no recibió ningún botón que tocar: la salida sigue siendo decorativa.');

        return $botones[0]['reply']['id'];
    }

    private function refrescada(Tenant $tenant, string $telefono = self::TELEFONO): Conversation
    {
        return TenantContext::runAs($tenant->id, fn () => Conversation::where('user_phone', $telefono)->firstOrFail());
    }

    // ------------------------------------------- AC-20.1 · la marca y su hora

    /** AC-20.1 · Tocar «Hablar con una persona» deja motivo y hora. */
    public function test_derivar_por_agenda_llena_deja_motivo_y_hora(): void
    {
        $tenant = $this->tenant();
        $this->fakes();

        $boton = $this->hastaLaAgendaLlena();
        $this->toca($boton);

        $c = $this->refrescada($tenant);

        $this->assertSame(MotivoDeDerivacion::NoAvailability->value, $c->handoff_reason,
            'La derivación no quedó marcada con su motivo.');
        $this->assertNotNull($c->handoff_at, 'La derivación no quedó marcada con su hora.');
        // RNF-02 · La hora se guarda en UTC: 10:00 en Buenos Aires son las 13:00.
        $this->assertSame('2026-08-24 13:00:00', $c->handoff_at->format('Y-m-d H:i:s'));
        $this->assertNull($c->handoff_resolved_at);

        $this->assertSame(1, $this->vecesQueSeAviso(),
            'Al cliente no se le avisó —o se le avisó de más— que lo iba a atender una persona.');
    }

    /** AC-20.1 · Y la conversación **no pierde el estado** en el que estaba. */
    public function test_derivar_no_se_lleva_puesto_el_estado(): void
    {
        $tenant = $this->tenant();
        $this->fakes();

        $boton = $this->hastaLaAgendaLlena();
        $estadoAntes = $this->refrescada($tenant)->current_state;

        $this->toca($boton);

        $this->assertSame($estadoAntes, $this->refrescada($tenant)->current_state,
            'La derivación movió el estado: al resolverla, el cliente tendría que empezar de nuevo.');
    }

    // ------------------------------------------------- AC-20.2 · el panel

    /** AC-20.2 · Las derivadas aparecen en el panel como pendientes. */
    public function test_el_panel_lista_las_derivaciones_pendientes(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));
        $c = $this->conversacion($tenant, nombre: 'Rocío');

        TenantContext::runAs($tenant->id, fn () => Derivacion::activar($c, MotivoDeDerivacion::NoAvailability));

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('Esperando que los atienda una persona (1)')
            ->assertSee('Rocío', false)
            ->assertSee(MotivoDeDerivacion::NoAvailability->etiqueta(), false)
            // RNF-02 · La hora que ve la PyME es la suya, no UTC.
            ->assertSee('24/08/2026 10:00');
    }

    /** AC-20.2 · Y salen **ordenadas por antigüedad**: primero la que más espera. */
    public function test_los_pendientes_salen_por_antiguedad(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));

        $vieja = $this->conversacion($tenant, '5493764111111', nombre: 'La que espera hace rato');
        $nueva = $this->conversacion($tenant, '5493764222222', nombre: 'La recién derivada');

        TenantContext::runAs($tenant->id, function () use ($vieja, $nueva) {
            Derivacion::activar($vieja, MotivoDeDerivacion::UnknownInput);
            $this->travel(45)->minutes();
            Derivacion::activar($nueva, MotivoDeDerivacion::NoAvailability);
        });

        /*
         * Se comparan las URL de resolver y no los teléfonos: los teléfonos
         * aparecen también en el listado general de abajo, que va ordenado al
         * revés, y la aserción podría pasar por el motivo equivocado.
         */
        $this->get(self::URL)
            ->assertOk()
            ->assertSeeInOrder([
                self::URL."/{$vieja->id}/resolver",
                self::URL."/{$nueva->id}/resolver",
            ], false);
    }

    // ----------------------------------------- AC-20.3 · el bot no pisa

    /** AC-20.3 · Mientras está derivada, el bot no retoma el flujo. */
    public function test_el_bot_no_contesta_mientras_esta_derivada(): void
    {
        $tenant = $this->tenant();
        $this->fakes();

        $boton = $this->hastaLaAgendaLlena();
        $this->toca($boton);

        $enviadosAntes = count($this->salientes());

        $this->escribe('¿Hola? ¿Hay alguien?');
        $this->escribe('Necesito el turno');

        $this->assertCount($enviadosAntes, $this->salientes(),
            'El bot contestó por encima de la persona a la que se derivó la conversación.');
    }

    // ------------------------------------------- AC-20.4 · sin duplicados

    /**
     * AC-20.4 · Una segunda condición no crea otro pendiente ni repite el mensaje.
     *
     * Se fuerza el caso real: vencida la ventana de la pausa el bot vuelve a
     * atender (AC-20.3 lo permite), el cliente toca otra vez «Hablar con una
     * persona» y **sigue habiendo un solo pendiente**.
     */
    public function test_una_segunda_derivacion_no_duplica_el_pendiente(): void
    {
        $tenant = $this->tenant();
        $this->fakes();

        $boton = $this->hastaLaAgendaLlena();
        $this->toca($boton);

        $derivadaEl = $this->refrescada($tenant)->handoff_at;

        $this->travel(Pausa::MINUTOS + 1)->minutes();
        $this->toca($boton);

        $c = $this->refrescada($tenant);

        $this->assertSame(1, $this->vecesQueSeAviso(),
            'Al cliente se le repitió el mensaje de derivación: le confirma que del otro lado hay un robot.');
        $this->assertSame(
            $derivadaEl->format('Y-m-d H:i:s'),
            $c->handoff_at->format('Y-m-d H:i:s'),
            'La segunda derivación pisó la hora de la primera y el pendiente rejuveneció.',
        );
        $this->assertSame(1, TenantContext::runAs($tenant->id, fn () => Derivacion::pendientes()->count()),
            'Se generó un segundo pendiente para la misma conversación.');
    }

    // --------------------------- Umbral de intentos con entrada no reconocida

    /** Tras N intentos sin entender, se deriva en vez de seguir sin entender. */
    public function test_a_los_tres_intentos_sin_entender_se_deriva(): void
    {
        $tenant = $this->tenant();
        $this->fakes();
        $this->conversacion($tenant, estado: Estado::SelectingSlot);

        for ($i = 1; $i < Derivacion::INTENTOS_PARA_DERIVAR; $i++) {
            $this->escribe('askdjhaskjd '.$i);

            $this->assertNull($this->refrescada($tenant)->handoff_at,
                "Se derivó en el intento {$i}, antes del umbral.");
        }

        $this->escribe('no entiendo nada');

        $c = $this->refrescada($tenant);

        $this->assertSame(MotivoDeDerivacion::UnknownInput->value, $c->handoff_reason);
        $this->assertNotNull($c->handoff_at);
        $this->assertSame(1, $this->vecesQueSeAviso(),
            'Se llegó al umbral y el cliente no recibió el aviso de que lo va a atender una persona.');
    }

    /** Un mensaje que el bot **sí** entiende corta la racha de intentos. */
    public function test_un_mensaje_entendido_reinicia_el_contador(): void
    {
        $tenant = $this->tenant();
        $this->fakes();
        $this->conversacion($tenant, estado: Estado::SelectingSlot);

        $this->escribe('askdjhaskjd');
        $this->escribe('qwertyuiop');

        // AC-16.3 · La salida de emergencia: el bot entiende «menú».
        $this->escribe('menu');

        $this->escribe('otra vez cualquier cosa');

        $this->assertNull($this->refrescada($tenant)->handoff_at,
            'La racha sobrevivió a un mensaje que el bot entendió: el cliente que se destrabó solo quedó a un tropiezo de que lo deriven.');
    }

    // ------------------------------------------------- Resolver, desde el panel

    /** La acción de resolver devuelve la conversación al flujo automático. */
    public function test_resolver_devuelve_la_conversacion_al_bot(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));
        $this->fakes();

        $boton = $this->hastaLaAgendaLlena();
        $this->toca($boton);

        $c = $this->refrescada($tenant);

        $this->post(self::URL."/{$c->id}/resolver")->assertRedirect(self::URL);

        $resuelta = $this->refrescada($tenant);
        $this->assertNotNull($resuelta->handoff_resolved_at, 'La derivación no quedó marcada como resuelta.');
        // El motivo y la hora **no se borran**: son el histórico por causa.
        $this->assertSame(MotivoDeDerivacion::NoAvailability->value, $resuelta->handoff_reason);

        $this->assertFalse(
            TenantContext::runAs($tenant->id, fn () => Pausa::estaPausada($resuelta)),
            'Resolver la derivación no levantó la pausa: el bot sigue callado.',
        );

        $enviadosAntes = count($this->salientes());

        /*
         * Se prueba con «menú» y no con un texto cualquiera: en
         * `GATHERING_PARAMS` un texto libre todavía no tiene intérprete —el
         * recordatorio del paso sigue sin escribirse, con su ⚠️ en el job— así
         * que el silencio ahí no distinguiría "el bot volvió" de "el bot no sabe
         * qué contestar". La palabra de reinicio, en cambio, siempre responde.
         */
        $this->escribe('menu');

        $this->assertGreaterThan($enviadosAntes, count($this->salientes()),
            'Resuelta la derivación, el bot sigue sin contestarle al cliente.');
    }

    /** Resuelta, deja de aparecer entre los pendientes del panel. */
    public function test_al_resolver_sale_de_la_lista_de_pendientes(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant));
        $c = $this->conversacion($tenant, nombre: 'Rocío');

        TenantContext::runAs($tenant->id, fn () => Derivacion::activar($c, MotivoDeDerivacion::UnknownInput));

        $this->get(self::URL)->assertSee('Esperando que los atienda una persona (1)');

        $this->post(self::URL."/{$c->id}/resolver");

        $this->get(self::URL)
            ->assertOk()
            ->assertDontSee('Esperando que los atienda una persona');
    }

    /** Resolver es de los tres roles: quien atiende es quien cierra. */
    public function test_staff_puede_resolver(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->usuario($tenant, Role::Staff));
        $c = $this->conversacion($tenant);

        TenantContext::runAs($tenant->id, fn () => Derivacion::activar($c, MotivoDeDerivacion::UnknownInput));

        $this->post(self::URL."/{$c->id}/resolver")->assertRedirect(self::URL);

        $this->assertNotNull($this->refrescada($tenant)->handoff_resolved_at);
    }

    // ------------------------------------------------------ RNF-01 · aislamiento

    /** Un usuario de otro tenant no ve las derivaciones ajenas. */
    public function test_el_panel_no_muestra_derivaciones_de_otro_tenant(): void
    {
        $ajeno = $this->tenant('ajeno');
        $c = $this->conversacion($ajeno, '5493764999999', nombre: 'Cliente ajeno');
        TenantContext::runAs($ajeno->id, fn () => Derivacion::activar($c, MotivoDeDerivacion::UnknownInput));

        $mio = $this->tenant('mio', conIntegraciones: false);
        $this->actingAs($this->usuario($mio));

        $this->get(self::URL)
            ->assertOk()
            ->assertDontSee('5493764999999')
            ->assertDontSee('Esperando que los atienda una persona');
    }

    /** Y tampoco puede resolverlas: 403 y el pendiente sigue en pie. */
    public function test_no_se_puede_resolver_la_derivacion_de_otro_tenant(): void
    {
        $ajeno = $this->tenant('ajeno');
        $c = $this->conversacion($ajeno, '5493764999999');
        TenantContext::runAs($ajeno->id, fn () => Derivacion::activar($c, MotivoDeDerivacion::UnknownInput));

        $mio = $this->tenant('mio', conIntegraciones: false);
        $this->actingAs($this->usuario($mio));

        $this->post(self::URL."/{$c->id}/resolver")->assertForbidden();

        TenantContext::runAs($ajeno->id, function () use ($c) {
            $this->assertTrue(Derivacion::estaPendiente($c->fresh()),
                'Un usuario de otro tenant cerró la derivación de un negocio ajeno.');
            $this->assertTrue(Pausa::estaPausada($c->fresh()),
                'Un usuario de otro tenant le devolvió el bot a un cliente que espera a una persona.');
        });
    }

    /** Sin sesión no se resuelve nada. */
    public function test_sin_login_no_se_puede_resolver(): void
    {
        $tenant = $this->tenant();
        $c = $this->conversacion($tenant);

        $this->post(self::URL."/{$c->id}/resolver")->assertRedirect('/login');
    }

    // ------------------------------------- el segundo número (modelo de dos números)

    /**
     * El mensaje de derivación **dice a dónde escribir**.
     *
     * Con dos números, el cliente tiene que cambiar de chat. Si no le decimos a
     * cuál, la derivación no lleva a ningún lado.
     */
    public function test_el_mensaje_de_derivacion_dice_el_numero_de_atencion(): void
    {
        $tenant = $this->tenant();

        $config = \App\Models\BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)->firstOrFail();
        $config->human_phone = '+54 9 11 5555-1234';
        $config->save();

        $texto = \App\Conversacion\Derivacion::mensajePara($config->fresh());

        $this->assertStringContainsString('+54 9 11 5555-1234', $texto);
    }

    /**
     * Sin número cargado, el bot **no promete que alguien escriba**.
     *
     * El texto original decía "te va a escribir por acá", y con la Cloud API eso
     * es imposible: nadie puede escribir por el número del bot. Prometerlo manda
     * al cliente a esperar una respuesta que no llega nunca.
     */
    public function test_sin_numero_cargado_no_promete_que_alguien_escriba(): void
    {
        $tenant = $this->tenant();

        $config = \App\Models\BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)->firstOrFail();

        $texto = \App\Conversacion\Derivacion::mensajePara($config);

        $this->assertStringNotContainsString('por acá', $texto);
        $this->assertStringNotContainsString('escribir', $texto);
        $this->assertNotSame('', trim($texto), 'El cliente quedaria sin ninguna respuesta.');
    }
}
