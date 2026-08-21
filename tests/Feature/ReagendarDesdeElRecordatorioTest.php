<?php

namespace Tests\Feature;

use App\Conversacion\Interactivo\IdSellado;
use App\Conversacion\ListaDeHorarios;
use App\Conversacion\ReservaTemporal;
use App\Jobs\ProcessMessageJob;
use App\Meta\AltaDeCuenta;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\HoraLocal;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-042 · Re-agendar desde el recordatorio t-24h.
 *
 * Empieza donde termina `RespuestaAlRecordatorioT24hTest`: el recordatorio ya
 * salió y el cliente toca **Re-agendar**, el botón que hasta hoy derivaba a una
 * persona porque este ticket estaba diferido.
 *
 * ## ⚠️ El catálogo de flujos y el ticket se contradicen, y gana el ticket
 *
 * `03-flujos/05-estados-flujo-conversacional.md` describe `RESCHEDULED` como
 * *«Cita previa anulada; el flujo retoma la selección de un nuevo horario»* —o
 * sea, anular primero y elegir después—. **El ticket exige lo contrario y lo
 * dice dos veces:** *«El turno nuevo se crea antes de borrar el viejo: no hay
 * ventana en la que el cliente se quede sin ninguno»* y *(AC-11.3)* *«pasados 30
 * minutos el turno original sigue vigente»*.
 *
 * Anular primero produce el peor caso posible: el cliente pide mover el turno,
 * la agenda no tiene otro horario que le sirva, y **se queda sin ninguno**
 * habiendo tenido uno. Se resuelve a favor del ticket y queda anotado para
 * corregir el catálogo.
 *
 * ## Decisiones que hubo que tomar para poder escribir estos tests
 *
 * | Elección | Por qué |
 * | :-- | :-- |
 * | El turno viejo queda en `rescheduled`, no en `cancelled` | El ENUM de `bookings.status` ya lo tiene, y distinguirlos es lo que hace medible *(AC-17.4)*: «canceló» y «movió» son dos conductas distintas y el pitch las cuenta distinto |
 * | Se afirma **el efecto**, no el copy | Ningún documento fija los textos del re-agendamiento. Se afirma que la lista llega y que la confirmación nombra la fecha nueva |
 * | El evento nuevo y el viejo tienen `id` distintos en el doble | Si el doble devolviera siempre el mismo `id`, «se creó el nuevo» y «se borró el viejo» serían indistinguibles y los tests pasarían sin probar nada |
 */
class ReagendarDesdeElRecordatorioTest extends TestCase
{
    use RefreshDatabase;

    private const COMANDO = 'recordatorios:enviar';

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '5493764278402';

    /** UTC−5 todo el año: no comparte offset con Buenos Aires en ninguna fecha. */
    private const TZ = 'America/Bogota';

    /** Jueves. */
    private const AHORA = '2026-08-20 12:00:00';

    private const REAGENDAR = 1;

    private const EVENTO_VIEJO = 'evt_google_VIEJO';

    private Tenant $tenant;

    private Integration $meta;

    private bool $altaHecha = false;

    private int $salientes = 0;

    private int $recordatorios = 0;

    private int $eventosCreados = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC'));
        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto-'.uniqid(),
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $this->meta = Integration::create([
            'tenant_id' => $this->tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => self::PHONE_NUMBER_ID, 'access_token' => 'meta',
            'settings' => ['verify_token' => 'tok', 'waba_id' => 'waba-'.self::PHONE_NUMBER_ID],
            'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $this->tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio@peluqueria.com', 'access_token' => 'ya29',
            'refresh_token' => '1//r', 'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------- montaje

    private function conversacion(): Conversation
    {
        return TenantContext::runAs($this->tenant->id, fn () => Conversation::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'user_phone' => self::TELEFONO],
            [
                'current_state' => 'BOOKED',
                'state_version' => 4,
                'context_data' => ['nombre' => 'Ana', 'servicio' => 'Corte de pelo'],
                'last_interaction_at' => now(),
            ]
        ));
    }

    private function turno(?CarbonImmutable $inicio = null): Booking
    {
        $inicio ??= CarbonImmutable::now('UTC')->addHours(24);
        $conversacion = $this->conversacion();

        return TenantContext::runAs($this->tenant->id, function () use ($inicio, $conversacion) {
            $google = Integration::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->first();

            return Booking::create([
                'tenant_id' => $this->tenant->id,
                'conversation_id' => $conversacion->id,
                'integration_id' => $google->id,
                'external_event_id' => self::EVENTO_VIEJO,
                'client_name' => 'Ana',
                'client_phone' => self::TELEFONO,
                'service_name' => 'Corte de pelo',
                'start_time' => $inicio,
                'end_time' => $inicio->addMinutes(30),
                'status' => Booking::ESTADO_AGENDADO,
                'attendance' => Booking::ASISTENCIA_PENDIENTE,
            ]);
        });
    }

    // ------------------------------------------------------------- dobles

    /**
     * **Un solo `Http::fake()` por test**: los stubs se acumulan, así que un
     * segundo llamado no reemplaza al primero y el doble nuevo nunca se usa.
     *
     * @param  array<int,array<int,string>>  $ocupados  Franjas ocupadas en Google.
     */
    private function fakes(array $ocupados = []): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => [
                    'busy' => array_map(fn ($p) => ['start' => $p[0], 'end' => $p[1]], $ocupados),
                ]],
            ]),

            'www.googleapis.com/calendar/v3/calendars/primary/events*' => function (Request $request) {
                if ($request->method() === 'DELETE') {
                    return Http::response([], 204);
                }

                // Un `id` distinto por creación: si todos fueran iguales, "se
                // creó el nuevo" y "se borró el viejo" serían el mismo hecho.
                return Http::response([
                    'id' => 'evt_google_NUEVO_'.(++$this->eventosCreados),
                    'status' => 'confirmed',
                    'extendedProperties' => ['private' => ['origen' => 'agendallena']],
                ]);
            },

            'graph.facebook.com/*' => function (Request $request) {
                if (str_contains($request->url(), 'message_templates')) {
                    return Http::response([
                        'id' => (string) random_int(1_000_000, 9_999_999),
                        'status' => 'APPROVED',
                        'category' => 'UTILITY',
                    ]);
                }

                $this->salientes++;

                $id = ($request->data()['type'] ?? null) === 'template'
                    ? 'wamid.RECORDATORIO_'.(++$this->recordatorios)
                    : 'wamid.OUT_'.$this->salientes;

                return Http::response(['messages' => [['id' => $id]]]);
            },
        ]);
    }

    // ---------------------------------------------------------- utilidades

    /**
     * Manda el recordatorio y devuelve su `wamid` y los payloads de sus botones.
     *
     * @return array<string,mixed>
     */
    private function mandarRecordatorio(): array
    {
        $antes = $this->recordatorios;

        if (! $this->altaHecha) {
            $this->altaHecha = true;
            TenantContext::runAs((string) $this->tenant->id,
                fn () => app(AltaDeCuenta::class)->crearPlantillas($this->meta));
        }

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame($antes + 1, $this->recordatorios,
            'No salió ningún recordatorio: sin él no hay botón que tocar.');

        $plantilla = Http::recorded(
            fn ($req, $res) => ($req->data()['type'] ?? null) === 'template'
        )->last()[0]->data();

        $botones = [];

        foreach ($plantilla['template']['components'] ?? [] as $componente) {
            if (($componente['type'] ?? null) === 'button') {
                $botones[(int) ($componente['index'] ?? 0)] = $componente['parameters'][0]['payload'] ?? null;
            }
        }

        ksort($botones);

        return ['wamid' => 'wamid.RECORDATORIO_'.$this->recordatorios, 'botones' => $botones];
    }

    /** El cliente toca un botón del recordatorio. */
    private function tocar(array $recordatorio, int $indice): void
    {
        $payload = $recordatorio['botones'][$indice] ?? null;

        $this->assertNotNull($payload, "El recordatorio no trajo el botón {$indice}.");

        $this->entra([
            'from' => self::TELEFONO,
            'id' => 'wamid.IN_'.uniqid(),
            'type' => 'button',
            'button' => ['text' => ['Confirmar', 'Re-agendar', 'Cancelar'][$indice], 'payload' => $payload],
            'context' => ['id' => $recordatorio['wamid']],
        ]);
    }

    /** @param  array<string,mixed>  $mensaje */
    private function entra(array $mensaje): void
    {
        (new ProcessMessageJob(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
                'messages' => [$mensaje],
            ],
        ]]]]]))->handle();
    }

    /**
     * Los mensajes que el cliente vio en el chat. Se filtran las plantillas del
     * alta: crearlas es un POST a `graph.facebook.com` y no es un mensaje.
     *
     * @return array<int,array<string,mixed>>
     */
    private function mensajesEnElChat(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            $data = $req->data();

            if (str_contains($req->url(), 'graph.facebook.com')
                && ! str_contains($req->url(), 'message_templates')
                && $res->successful()
                && ($data['type'] ?? null) !== 'template') {
                $out[] = $data;
            }
        }

        return $out;
    }

    /**
     * Las listas interactivas de horarios que se le mandaron al cliente.
     *
     * @return array<int,array<string,mixed>>
     */
    private function listasDeHorarios(): array
    {
        return array_values(array_filter(
            $this->mensajesEnElChat(),
            fn (array $m): bool => ($m['type'] ?? null) === 'interactive'
                && ($m['interactive']['type'] ?? null) === 'list',
        ));
    }

    /**
     * Los ítems seleccionables de la última lista enviada.
     *
     * @return array<int,array<string,mixed>>
     */
    private function horariosOfrecidos(): array
    {
        $listas = $this->listasDeHorarios();

        if ($listas === []) {
            return [];
        }

        $ultima = $listas[count($listas) - 1];

        $items = [];

        foreach ($ultima['interactive']['action']['sections'] ?? [] as $seccion) {
            foreach ($seccion['rows'] ?? [] as $fila) {
                $items[] = $fila;
            }
        }

        return $items;
    }

    private function estadoDeLaConversacion(): string
    {
        return (string) TenantContext::runAs($this->tenant->id,
            fn () => Conversation::withoutTenantScope()
                ->where('user_phone', self::TELEFONO)->value('current_state'));
    }

    private function estadoDelTurno(Booking $booking): string
    {
        return (string) TenantContext::runAs($this->tenant->id,
            fn () => Booking::withoutTenantScope()->where('id', $booking->id)->value('status'));
    }

    // -------------------------------------------- AC-11.1 · vuelve a la lista

    /**
     * AC-11.1 · Tocar Re-agendar trae **la lista de horarios disponibles**.
     *
     * Es todo el valor del ticket: sin esto el botón deriva a una persona, que
     * es trabajo manual para la PyME — justo lo que el producto viene a eliminar.
     */
    public function test_tocar_reagendar_trae_la_lista_de_horarios(): void
    {
        $this->fakes();
        $this->turno();

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);

        $this->assertNotEmpty($this->horariosOfrecidos(),
            'El cliente tocó Re-agendar y no le llegó ninguna lista de horarios: el botón sigue '
            .'sin poder mover el turno.');
    }

    /**
     * AC-11.1 · Y **sin volver a pedir los datos**.
     *
     * El nombre ya está en el turno original. Volver a pedirlo convierte un
     * cambio de horario en el flujo de alta entero, que es exactamente el costo
     * que hace que la gente prefiera llamar por teléfono.
     */
    public function test_reagendar_no_vuelve_a_pedir_los_datos(): void
    {
        $this->fakes();
        $this->turno();

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);

        $this->assertSame('SELECTING_SLOT', $this->estadoDeLaConversacion(),
            'La conversación no quedó eligiendo horario. Si volvió a GATHERING_PARAMS, se le está '
            .'pidiendo de nuevo un nombre que ya está en el turno original.');
    }

    /** El cliente elige un horario de la lista que le llego. */
    private function elegirHorario(int $indice = 0): void
    {
        $items = $this->horariosOfrecidos();

        $this->assertArrayHasKey($indice, $items,
            'No hay lista de horarios para elegir: el paso anterior no la mando.');

        $this->entra([
            'from' => self::TELEFONO,
            'id' => 'wamid.IN_'.uniqid(),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list_reply',
                'list_reply' => ['id' => $items[$indice]['id'], 'title' => $items[$indice]['title'] ?? 'x'],
            ],
        ]);
    }

    /**
     * Los `id` de evento cuyo borrado se le pidio a Google.
     *
     * `values()` no es adorno: `Http::recorded()` con callback conserva las
     * claves originales, asi que si el DELETE no fue la primera peticion el
     * array vuelve sin la clave 0.
     *
     * @return array<int,string>
     */
    private function eventosBorrados(): array
    {
        return Http::recorded(
            fn ($req, $res) => $req->method() === 'DELETE' && str_contains($req->url(), 'googleapis.com')
        )->map(fn ($par) => urldecode(basename((string) parse_url($par[0]->url(), PHP_URL_PATH))))->values()->all();
    }

    /**
     * La secuencia de operaciones sobre eventos de Google en el orden en que
     * ocurrieron. Es lo unico que puede probar el criterio del orden.
     *
     * @return array<int,string>
     */
    private function operacionesEnGoogle(): array
    {
        $ops = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (! str_contains($req->url(), 'googleapis.com/calendar/v3/calendars')) {
                continue;
            }

            $ops[] = $req->method() === 'DELETE' ? 'borrar' : 'crear';
        }

        return $ops;
    }

    /** @return array<int,array<string,mixed>> Los turnos del tenant, del mas viejo al mas nuevo. */
    private function turnosDelTenant(): array
    {
        return TenantContext::runAs($this->tenant->id, fn () => Booking::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'external_event_id' => $b->external_event_id,
                'status' => $b->status,
                'start_time' => $b->start_time,
            ])
            ->all());
    }

    /**
     * Los textos que se le mandaron al cliente.
     *
     * @return array<int,string>
     */
    private function textosEnElChat(): array
    {
        $out = [];

        foreach ($this->mensajesEnElChat() as $m) {
            if (($m['type'] ?? null) === 'text') {
                $out[] = (string) ($m['text']['body'] ?? '');
            }
        }

        return $out;
    }

    // ------------------------------------------ AC-11.2 · se mueve de verdad

    /**
     * AC-11.2 · Al confirmar el horario nuevo **se borra el evento original**.
     *
     * Sin esto el cliente termina con dos turnos y la PyME con una franja
     * bloqueada para nadie, que es peor que no haber movido nada.
     */
    public function test_confirmar_el_horario_nuevo_borra_el_evento_original(): void
    {
        $this->fakes();
        $this->turno();

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);
        $this->elegirHorario();

        $this->assertContains(self::EVENTO_VIEJO, $this->eventosBorrados(),
            'El turno nuevo se creo y el evento viejo sigue en el calendario: el cliente quedo con '
            .'dos turnos y la PyME con una franja ocupada por nadie.');
    }

    /** AC-11.2 · Queda un turno nuevo y el viejo deja de estar vivo. */
    public function test_queda_un_turno_nuevo_y_el_viejo_deja_de_estar_vivo(): void
    {
        $this->fakes();
        $viejo = $this->turno();

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);
        $this->elegirHorario();

        $this->assertCount(2, $this->turnosDelTenant(),
            'Deberia haber dos filas: la del turno movido y la del nuevo. Reusar la fila borra el '
            .'rastro de que hubo un re-agendamiento, que es el dato que el piloto quiere medir.');

        $this->assertNotSame(Booking::ESTADO_AGENDADO, $this->estadoDelTurno($viejo),
            'El turno original sigue como `scheduled` despues de moverse: el panel, el registro de '
            .'asistencia y el recordatorio lo siguen tratando como un turno vivo.');
    }

    /**
     * El turno viejo queda **distinguible de una cancelacion**.
     *
     * «Cancelo» y «movio» son dos conductas distintas y el argumento de venta las
     * cuenta distinto: una es un turno perdido y la otra es un turno conservado.
     * El ENUM de `bookings.status` ya tiene `rescheduled`.
     */
    public function test_el_turno_movido_se_distingue_de_uno_cancelado(): void
    {
        $this->fakes();
        $viejo = $this->turno();

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);
        $this->elegirHorario();

        $this->assertSame('rescheduled', $this->estadoDelTurno($viejo),
            'El turno movido quedo indistinguible de uno cancelado: se pierde la diferencia entre '
            .'un cliente que se fue y uno que solo cambio de hora.');
    }

    /**
     * **El turno nuevo se crea antes de borrar el viejo.**
     *
     * Es el criterio que hace segura toda la operacion: si se borrara primero y
     * Google fallara al crear el nuevo, el cliente se queda sin ningun turno
     * habiendo pedido moverlo. Se prueba por el **orden real** de las llamadas.
     */
    public function test_el_turno_nuevo_se_crea_antes_de_borrar_el_viejo(): void
    {
        $this->fakes();
        $this->turno();

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);
        $this->elegirHorario();

        $ops = $this->operacionesEnGoogle();

        // Sin un borrado, "creo antes de borrar" es cierto por vacuidad: este
        // test pasaba igual con el borrado del evento viejo desactivado.
        $this->assertContains('borrar', $ops,
            'No se borro ningun evento: el orden no prueba nada si nunca se suelta el viejo.');

        $this->assertSame('crear', $ops[0],
            'La primera operacion sobre el calendario fue borrar. Si Google falla al crear el turno '
            .'nuevo, el cliente se queda sin ninguno. Secuencia observada: '.implode(' -> ', $ops));
    }

    /** AC-11.2 · Y la confirmacion que llega nombra **la fecha nueva**. */
    public function test_la_confirmacion_nombra_la_fecha_nueva(): void
    {
        $this->fakes();
        $viejo = $this->turno();

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);
        $this->elegirHorario();

        $turnos = $this->turnosDelTenant();
        $nuevo = $turnos[count($turnos) - 1];

        $this->assertNotSame(
            $viejo->start_time->toIso8601String(),
            $nuevo['start_time']->toIso8601String(),
            'El turno nuevo quedo en el mismo horario que el viejo: el test no prueba nada.'
        );

        $cuando = HoraLocal::completa($nuevo['start_time'], $this->tenant->fresh());

        $this->assertNotEmpty(array_filter(
            $this->textosEnElChat(),
            fn (string $t): bool => str_contains($t, $cuando),
        ), 'Ningun mensaje le dijo al cliente para cuando quedo su turno. Textos enviados: '
            .implode(' | ', $this->textosEnElChat()));
    }

    // ------------------------------- AC-11.3 · abandonar no pierde el turno

    /**
     * AC-11.3 · Abandonando sin elegir, a los 30 minutos **el turno original
     * sigue vigente**.
     *
     * Es la razon por la que el catalogo de flujos esta mal: si `RESCHEDULED`
     * anulara la cita previa, el que se distrae leyendo la lista se queda sin
     * turno.
     */
    public function test_abandonar_el_reagendamiento_deja_vigente_el_turno_original(): void
    {
        $this->fakes();
        $viejo = $this->turno();

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);

        // Pasan los 30 minutos de inactividad de T-007 y corre la expiracion.
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC')->addMinutes(31));
        $this->artisan('conversaciones:expirar')->assertSuccessful();

        $this->assertSame(Booking::ESTADO_AGENDADO, $this->estadoDelTurno($viejo),
            'El cliente pidio mover el turno, se distrajo, y perdio el turno que ya tenia.');

        $this->assertSame([], $this->eventosBorrados(),
            'Se borro el evento original sin que el cliente eligiera ningun horario nuevo.');
    }

    // --------------------------------- AC-18.4 · la colision tambien aplica

    /**
     * AC-18.4 · El control de colision de T-029 **aplica tambien a este camino**.
     *
     * Es el criterio que T-029 dejo declarado sin cubrir porque no habia
     * re-agendamiento. Si otro cliente aparto ese horario entre que se mostro la
     * lista y que este lo eligio, no puede crearse el evento encima.
     */
    public function test_el_control_de_colision_aplica_al_reagendamiento(): void
    {
        $this->fakes();
        $this->turno();

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);

        $items = $this->horariosOfrecidos();
        $this->assertNotEmpty($items, 'No llego la lista: el test no puede probar la colision.');

        $sello = IdSellado::leer($items[0]['id']);
        $this->assertNotNull($sello, 'El item de la lista no trae un id sellado legible.');

        // El instante viaja dentro de la accion del sello: se lee con el mismo
        // metodo que usa el producto, no re-implementando el formato aca.
        $horario = ListaDeHorarios::horarioDe($sello->accion);
        $this->assertNotNull($horario, 'El item de la lista no resuelve a un horario.');

        $otra = TenantContext::runAs($this->tenant->id, fn () => Conversation::create([
            'tenant_id' => $this->tenant->id,
            'user_phone' => '5491133445566',
            'current_state' => 'SELECTING_SLOT',
            'state_version' => 2,
            'context_data' => ['nombre' => 'Otro'],
            'last_interaction_at' => now(),
        ]));

        $this->assertTrue(
            TenantContext::runAs($this->tenant->id, fn () => ReservaTemporal::apartar($otra, $horario)),
            'El otro cliente no pudo apartar el horario: el montaje del test no vale.'
        );

        $creadosAntes = $this->eventosCreados;

        $this->elegirHorario();

        $this->assertSame($creadosAntes, $this->eventosCreados,
            'Se creo el evento sobre un horario que otro cliente tenia apartado: el control de '
            .'colision de T-029 no se aplica al re-agendamiento (AC-18.4).');
    }

    // ------------------- el candado del esquema, con el estado nuevo adentro

    /**
     * ⚠️ **T-042 reabre el bug que se corrigio el 2026-08-21 si nadie lo mira.**
     *
     * `live_event_id` vale `NULL` cuando el turno se cancela, y por eso la fila
     * cancelada suelta el unico `(tenant_id, live_event_id)` sola. Pero el estado
     * `rescheduled` es nuevo: una fila re-agendada **sigue reclamando su evento**
     * aunque ese evento ya no exista en Google, porque lo borramos al mover el
     * turno.
     *
     * El camino real es este, y no es raro: el cliente mueve su turno de las 15 a
     * las 16, se arrepiente y vuelve a pedir las 15. `ClaveDeIdempotencia` es
     * deterministica —mismo tenant, misma conversacion, mismo instante—, asi que
     * el turno nuevo de las 15 recibe **el mismo `external_event_id`** que tenia
     * el viejo. El `INSERT` choca contra la fila re-agendada, y
     * `Reserva::turnoYaPersistido()` la devuelve como si el turno acabara de
     * agendarse: *"quedo reservado"* sin evento ni turno vivo.
     *
     * Es el mismo defecto de la cancelacion, con otro estado. Se prueba sobre el
     * esquema porque **el candado tiene que estar en el esquema**: una
     * comprobacion en PHP deja la ventana de carrera abierta entre dos workers.
     */
    public function test_un_turno_reagendado_suelta_el_evento_que_ya_no_ocupa(): void
    {
        $this->fakes();
        $viejo = $this->turno();

        TenantContext::runAs($this->tenant->id, function () use ($viejo) {
            $viejo->forceFill(['status' => Booking::ESTADO_REAGENDADO])->save();
        });

        $conversacion = $this->conversacion();

        $nuevo = TenantContext::runAs($this->tenant->id, function () use ($conversacion) {
            $google = Integration::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->first();

            $inicio = CarbonImmutable::now('UTC')->addHours(48);

            // El mismo `external_event_id` que la fila re-agendada: es lo que
            // devuelve la clave de idempotencia cuando el cliente vuelve al
            // horario que habia dejado.
            return Booking::create([
                'tenant_id' => $this->tenant->id,
                'conversation_id' => $conversacion->id,
                'integration_id' => $google->id,
                'external_event_id' => self::EVENTO_VIEJO,
                'client_name' => 'Ana',
                'client_phone' => self::TELEFONO,
                'service_name' => 'Corte de pelo',
                'start_time' => $inicio,
                'end_time' => $inicio->addMinutes(30),
                'status' => Booking::ESTADO_AGENDADO,
                'attendance' => Booking::ASISTENCIA_PENDIENTE,
            ]);
        });

        $this->assertNotSame($viejo->id, $nuevo->id,
            'El turno nuevo reuso la fila re-agendada en vez de crear una propia.');

        $this->assertSame(Booking::ESTADO_AGENDADO, $this->estadoDelTurno($nuevo),
            'El turno nuevo no quedo vivo.');
    }

    /**
     * Y el candado **sigue puesto entre dos turnos vivos**: soltar el evento de
     * la fila re-agendada no puede convertirse en soltarlo para todos.
     */
    public function test_dos_turnos_vivos_siguen_sin_poder_reclamar_el_mismo_evento(): void
    {
        $this->fakes();
        $this->turno();

        $conversacion = $this->conversacion();

        $this->expectException(\Illuminate\Database\QueryException::class);

        TenantContext::runAs($this->tenant->id, function () use ($conversacion) {
            $google = Integration::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->first();

            $inicio = CarbonImmutable::now('UTC')->addHours(48);

            Booking::create([
                'tenant_id' => $this->tenant->id,
                'conversation_id' => $conversacion->id,
                'integration_id' => $google->id,
                'external_event_id' => self::EVENTO_VIEJO,
                'client_name' => 'Otra',
                'client_phone' => self::TELEFONO,
                'service_name' => 'Corte de pelo',
                'start_time' => $inicio,
                'end_time' => $inicio->addMinutes(30),
                'status' => Booking::ESTADO_AGENDADO,
                'attendance' => Booking::ASISTENCIA_PENDIENTE,
            ]);
        });
    }
}
