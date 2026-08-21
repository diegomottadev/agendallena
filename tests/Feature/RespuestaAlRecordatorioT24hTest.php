<?php

namespace Tests\Feature;

use App\Jobs\ProcessMessageJob;
use App\Meta\AltaDeCuenta;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-037 · Qué pasa cuando el cliente toca un botón del recordatorio.
 *
 * El envío está en `RecordatorioT24hTest`. Acá empieza donde aquel termina: el
 * recordatorio ya salió y el cliente contesta.
 *
 * ## La respuesta se resuelve por el `wamid`, no por el estado de la conversación
 *
 * Verificado contra Meta real el 2026-08-20: el webhook de un botón de plantilla
 * llega así, con `context.id` apuntando al recordatorio que lo originó:
 *
 * ```json
 * {"type":"button","button":{"text":"Confirmar","payload":"confirmar|BOOKED|4"},
 *  "context":{"id":"wamid.RECORDATORIO_1"}}
 * ```
 *
 * Como `notification_logs` guarda el `wamid` del envío **por turno**, ese
 * `context.id` alcanza para saber a qué turno corresponde la respuesta sin
 * preguntarle nada a la conversación. Es el camino robusto y es el que estos
 * tests exigen: cubre al que confirma tres días después, al que tiene dos turnos
 * abiertos y al que mientras tanto reinició la conversación.
 *
 * ## Ambigüedades que tuve que resolver
 *
 * ⚠️ **AC-10.3 dice "el horario vuelve a estar disponible" y no dice cómo.** La
 * disponibilidad la calcula `ConsultorDeDisponibilidad` con `freeBusy` de
 * Google, así que **borrar el evento es lo que libera la franja**; pero un
 * `bookings` que siga diciendo `scheduled` deja el turno vivo para el panel, los
 * reportes y el recordatorio t-2h. Se afirman las dos cosas y se dice acá que la
 * segunda es interpretación.
 *
 * ⚠️ **El texto del botón Re-agendar no lo fija nadie.** T-042 está diferido. Se
 * afirma lo único que el ticket sí exige —que no quede mudo— y no un copy
 * inventado.
 *
 * ## ⚠️ Por qué cambió el montaje el 2026-08-20 (T-050)
 *
 * El tenant de este archivo se montaba con `settings => ['verify_token']` y nada
 * más. **T-050 hizo del `waba_id` una precondición del envío:** con una cuenta de
 * WhatsApp por PyME, las plantillas se aprueban **por cuenta**, así que un tenant
 * sin `waba_id` y sin plantillas aprobadas en la suya no puede mandar el
 * recordatorio — y sin recordatorio no hay botón que tocar, que es todo lo que
 * este archivo prueba.
 *
 * Es una **expectativa vieja, no una regresión**: el montaje representaba un
 * tenant que bajo el modelo de una cuenta por PyME no puede existir. Por eso
 * ahora se le da de alta la cuenta antes de mandar el recordatorio, y
 * **ninguna aserción cambió**.
 */
class RespuestaAlRecordatorioT24hTest extends TestCase
{
    use RefreshDatabase;

    /** ⚠️ Elección mía; ver `RecordatorioT24hTest`. */
    private const COMANDO = 'recordatorios:enviar';

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '5493764278402';

    private const TZ = 'America/Bogota';

    private const AHORA = '2026-08-20 12:00:00';

    /** Índices de los botones en la plantilla aprobada (T-002). */
    private const CONFIRMAR = 0;

    private const REAGENDAR = 1;

    private const CANCELAR = 2;

    private Tenant $tenant;

    /** La integración de Meta del tenant: su número y su cuenta (T-050). */
    private Integration $meta;

    private bool $altaHecha = false;

    private int $salientes = 0;

    private int $recordatorios = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC'));
        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto-'.uniqid(),
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        // T-050 · La cuenta de WhatsApp es de esta PyME. El alta se corre recién
        // en `mandarRecordatorio()`: acá todavía no hay `Http::fake()` puesto y
        // le pegaría a Meta de verdad.
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

    private function conversacion(string $telefono = self::TELEFONO): Conversation
    {
        return TenantContext::runAs($this->tenant->id, fn () => Conversation::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'user_phone' => $telefono],
            [
                'current_state' => 'BOOKED',
                'state_version' => 4,
                'context_data' => ['nombre' => 'Ana', 'servicio' => 'Corte de pelo'],
                'last_interaction_at' => now(),
            ]
        ));
    }

    private function turno(CarbonImmutable $inicio, string $eventId = 'evt_google_123', ?Conversation $conversacion = null): Booking
    {
        $conversacion ??= $this->conversacion();

        return TenantContext::runAs($this->tenant->id, function () use ($inicio, $eventId, $conversacion) {
            $google = Integration::query()
                ->where('tenant_id', $this->tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->first();

            return Booking::create([
                'tenant_id' => $this->tenant->id,
                'conversation_id' => $conversacion->id,
                'integration_id' => $google->id,
                'external_event_id' => $eventId,
                'client_name' => 'Ana',
                'client_phone' => $conversacion->user_phone,
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
     * **Un solo `Http::fake()` por test.** Google y Meta comparten el mapa: un
     * segundo `fake()` no reemplazaría al primero y el doble que importa nunca
     * se consumiría.
     */
    private function fakes(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => function (Request $request) {
                if ($request->method() === 'DELETE') {
                    return Http::response([], 204);
                }

                return Http::response([
                    'id' => 'evt_google_123',
                    'status' => 'confirmed',
                    'extendedProperties' => ['private' => ['origen' => 'agendallena']],
                ]);
            },

            'graph.facebook.com/*' => function (Request $request) {
                /*
                 * T-050 · Crear una plantilla en la cuenta del tenant también es
                 * un POST a `graph.facebook.com`, y **no es un mensaje**: no
                 * mueve el contador de salientes ni consume un `wamid`. Meta las
                 * devuelve aprobadas para que estos tests sigan probando la
                 * respuesta al botón y no el alta.
                 */
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
     * Manda el recordatorio del turno que esté en ventana y devuelve
     * `['wamid' => ..., 'botones' => [indice => payload]]`.
     *
     * Los payloads se leen **del envío real**, no se inventan acá: son los que
     * Meta va a devolver cuando el cliente toque el botón.
     */
    private function mandarRecordatorio(): array
    {
        $antes = $this->recordatorios;

        $this->completarElAltaDeLaCuenta();

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame($antes + 1, $this->recordatorios,
            'No salió ningún recordatorio: sin él no hay botón que tocar y este test '
            .'no prueba lo que dice probar.');

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

    /**
     * T-050 · Deja al tenant con sus tres plantillas aprobadas en su cuenta.
     *
     * Va por el camino del producto —el alta— y no escribiendo el estado a mano:
     * así el montaje no fija dónde se guarda el registro de aprobación, que es
     * una decisión del implementador. Corre acá y no en `setUp()` porque necesita
     * el `Http::fake()` puesto.
     */
    private function completarElAltaDeLaCuenta(): void
    {
        if ($this->altaHecha) {
            return;
        }

        $this->altaHecha = true;

        TenantContext::runAs((string) $this->tenant->id,
            fn () => app(AltaDeCuenta::class)->crearPlantillas($this->meta));
    }

    /**
     * El cliente toca un botón del recordatorio.
     *
     * La forma del webhook es la verificada contra Meta real: `type: button`,
     * el payload del botón y el `context.id` del recordatorio que lo originó.
     */
    private function tocar(array $recordatorio, int $indice, string $telefono = self::TELEFONO): void
    {
        $payload = $recordatorio['botones'][$indice] ?? null;

        $this->assertNotNull($payload, "El recordatorio no trajo el botón {$indice}.");

        $mensaje = [
            'from' => $telefono,
            'id' => 'wamid.IN_'.uniqid(),
            'type' => 'button',
            'button' => ['text' => ['Confirmar', 'Re-agendar', 'Cancelar'][$indice], 'payload' => $payload],
            'context' => ['id' => $recordatorio['wamid']],
        ];

        (new ProcessMessageJob(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
                'messages' => [$mensaje],
            ],
        ]]]]]))->handle();
    }

    /** Los mensajes que el cliente vio en el chat, sin contar las plantillas. */
    private function mensajesEnElChat(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            $data = $req->data();

            // El alta de T-050 crea plantillas contra `graph.facebook.com` y eso
            // **no es un mensaje en el chat**: sin este filtro, las tres altas
            // contarían como mensajes que el cliente nunca vio.
            if (str_contains($req->url(), 'graph.facebook.com')
                && ! str_contains($req->url(), 'message_templates')
                && $res->successful()
                && ($data['type'] ?? null) !== 'template') {
                $out[] = $data;
            }
        }

        return $out;
    }

    private function estadoDelTurno(Booking $booking): string
    {
        return (string) TenantContext::runAs(
            $this->tenant->id,
            fn () => Booking::withoutTenantScope()->where('id', $booking->id)->value('status')
        );
    }

    /**
     * ¿Se pidió el borrado de este evento en Google?
     *
     * `values()` no es adorno: `Http::recorded()` con callback **filtra
     * conservando las claves originales**, así que si el `DELETE` no fue la
     * primera petición registrada, el array vuelve sin la clave `0` y quien lo
     * lea por índice revienta con `Undefined array key 0`.
     */
    private function eventosBorrados(): array
    {
        return Http::recorded(
            fn ($req, $res) => $req->method() === 'DELETE' && str_contains($req->url(), 'googleapis.com')
        )->map(fn ($par) => $par[0]->url())->values()->all();
    }

    // ------------------------------------------------- AC-10.2 · Confirmar

    /**
     * AC-10.2 · Tocar Confirmar deja el turno en `confirmed`.
     *
     * **Es el dato que sostiene el número del pitch.** Sin él no hay forma de
     * distinguir al que confirmó del que nunca contestó, y sin esa distinción no
     * se puede medir si el ausentismo bajó.
     */
    public function test_confirmar_pasa_el_turno_a_confirmed(): void
    {
        $this->fakes();
        $booking = $this->turno(CarbonImmutable::now()->addHours(24));

        $this->tocar($this->mandarRecordatorio(), self::CONFIRMAR);

        $this->assertSame(Booking::ESTADO_CONFIRMADO, $this->estadoDelTurno($booking),
            'El cliente tocó Confirmar y el turno sigue como estaba: la confirmación se perdió.');
    }

    /**
     * AC-10.2 · Y el cliente recibe acuse en el chat.
     *
     * Un botón que se toca y no contesta nada se lee como que no funcionó: el
     * cliente vuelve a tocarlo, o llama por teléfono, que es justo lo que esta
     * historia viene a evitar.
     */
    public function test_confirmar_deja_acuse_en_el_chat(): void
    {
        $this->fakes();
        $this->turno(CarbonImmutable::now()->addHours(24));

        $recordatorio = $this->mandarRecordatorio();

        $antes = count($this->mensajesEnElChat());
        $this->tocar($recordatorio, self::CONFIRMAR);

        $this->assertGreaterThan($antes, count($this->mensajesEnElChat()),
            'El cliente tocó Confirmar y no recibió ningún acuse: para él, el botón no funcionó.');
    }

    // -------------------------------------------------- AC-10.3 · Cancelar

    /**
     * AC-10.3 · Cancelar borra **ese** evento de Google Calendar.
     *
     * Es lo que libera la franja de verdad: la disponibilidad se calcula con
     * `freeBusy` sobre el calendario del negocio, así que mientras el evento
     * exista el horario sigue bloqueado para todos los demás.
     */
    public function test_cancelar_borra_el_evento_de_google_calendar(): void
    {
        $this->fakes();
        $this->turno(CarbonImmutable::now()->addHours(24), eventId: 'evt_google_123');

        $this->tocar($this->mandarRecordatorio(), self::CANCELAR);

        $borrados = $this->eventosBorrados();

        $this->assertNotEmpty($borrados,
            'El cliente canceló y el evento sigue en el calendario del negocio: '
            .'el horario queda bloqueado por alguien que ya avisó que no va.');
        $this->assertStringContainsString('evt_google_123', $borrados[0],
            'Se borró un evento que no es el del turno cancelado.');
    }

    /**
     * AC-10.3 · Y el turno queda `cancelled` en la base.
     *
     * ⚠️ **Interpretación mía:** el criterio dice "el horario vuelve a estar
     * disponible" y eso, estrictamente, lo cumple borrar el evento. Pero un
     * `bookings` que siga diciendo `scheduled` deja el turno vivo para el panel,
     * para el registro de asistencia (T-036) y para el aviso t-2h (T-043), que
     * le escribiría a alguien que ya canceló.
     */
    public function test_cancelar_deja_el_turno_como_cancelado(): void
    {
        $this->fakes();
        $booking = $this->turno(CarbonImmutable::now()->addHours(24));

        $this->tocar($this->mandarRecordatorio(), self::CANCELAR);

        $this->assertSame(Booking::ESTADO_CANCELADO, $this->estadoDelTurno($booking),
            'El evento se borró de Google pero el turno sigue vivo en la base: '
            .'el panel y el aviso t-2h lo van a seguir tratando como un turno real.');
    }

    /**
     * AC-10.3 · Y llega la confirmación de la cancelación.
     */
    public function test_cancelar_avisa_al_cliente_que_el_turno_quedo_cancelado(): void
    {
        $this->fakes();
        $this->turno(CarbonImmutable::now()->addHours(24));

        $recordatorio = $this->mandarRecordatorio();

        $antes = count($this->mensajesEnElChat());
        $this->tocar($recordatorio, self::CANCELAR);

        $this->assertGreaterThan($antes, count($this->mensajesEnElChat()),
            'El turno se canceló y el cliente no recibió confirmación: no sabe si '
            .'quedó cancelado y va a llamar para preguntar.');
    }

    // ------------------------------------- el botón Re-agendar no queda mudo

    /**
     * El botón Re-agendar viaja en la plantilla aprobada y **su efecto es T-042,
     * que está diferido**.
     *
     * Un botón aprobado que no contesta es peor que no tenerlo: el cliente cree
     * que pidió un cambio de horario y se queda esperando una respuesta que no
     * va a llegar. Hasta T-042 tiene que decir algo honesto o derivar a una
     * persona.
     *
     * ⚠️ **No se afirma sobre el copy**: ningún documento lo fija, y escribir uno
     * acá sería inventar el criterio en vez de marcarlo.
     */
    public function test_reagendar_no_deja_al_cliente_sin_respuesta(): void
    {
        $this->fakes();
        $booking = $this->turno(CarbonImmutable::now()->addHours(24));

        $recordatorio = $this->mandarRecordatorio();

        $antes = count($this->mensajesEnElChat());
        $this->tocar($recordatorio, self::REAGENDAR);

        $this->assertGreaterThan($antes, count($this->mensajesEnElChat()),
            'El cliente tocó Re-agendar y el bot se quedó mudo: cree que pidió un '
            .'cambio de horario y espera una respuesta que no existe (T-042 está diferido).');
    }

    /**
     * Y **no hace de más**: mientras T-042 no exista, el turno no se toca.
     *
     * Resolver el botón "como si fuera Cancelar" dejaría al cliente sin turno
     * habiendo pedido moverlo.
     */
    public function test_reagendar_no_cancela_ni_confirma_el_turno(): void
    {
        $this->fakes();
        $booking = $this->turno(CarbonImmutable::now()->addHours(24));

        $this->tocar($this->mandarRecordatorio(), self::REAGENDAR);

        $this->assertSame(Booking::ESTADO_AGENDADO, $this->estadoDelTurno($booking),
            'Re-agendar cambió el estado del turno: T-042 está diferido y este botón '
            .'todavía no tiene efecto sobre la reserva.');
        $this->assertSame([], $this->eventosBorrados(),
            'Re-agendar borró el evento de Google: el cliente pidió mover el turno, '
            .'no perderlo.');
    }

    // ------------------------------- la resolución robusta: por el `context.id`

    /**
     * El cliente confirma **después de haber reiniciado la conversación**.
     *
     * Escenario real: el recordatorio llega el jueves, el cliente escribe "menú"
     * el viernes por otra consulta —T-021 lo devuelve a `IDLE` y sube la
     * versión—, y recién el sábado scrollea y toca Confirmar.
     *
     * El sello del botón dice `BOOKED` y una versión vieja, así que la regla de
     * AC-03.2 lo daría por caduco. **Pero `context.id` trae el `wamid` del
     * recordatorio**, y `notification_logs` lo ata a un turno: la respuesta se
     * resuelve igual.
     *
     * ⚠️ **Es una tensión con AC-03.2 que ningún documento resuelve.** La resuelvo
     * a favor de confirmar el turno: el sello existe para que no se ejecute la
     * acción de *otro paso del flujo*, y esta acción no es de un paso del flujo
     * —es la respuesta a un mensaje que le mandamos nosotros, sobre un turno que
     * sigue existiendo—.
     */
    public function test_la_confirmacion_se_resuelve_por_el_wamid_aunque_la_conversacion_haya_seguido(): void
    {
        $this->fakes();
        $conversacion = $this->conversacion();
        $booking = $this->turno(CarbonImmutable::now()->addHours(24), conversacion: $conversacion);

        $recordatorio = $this->mandarRecordatorio();

        // El cliente reinició la conversación entre el recordatorio y su respuesta.
        TenantContext::runAs($this->tenant->id, fn () => $conversacion->forceFill([
            'current_state' => 'IDLE',
            'state_version' => $conversacion->state_version + 3,
            'context_data' => [],
        ])->save());

        $this->tocar($recordatorio, self::CONFIRMAR);

        $this->assertSame(Booking::ESTADO_CONFIRMADO, $this->estadoDelTurno($booking),
            'La confirmación se descartó porque la conversación había seguido. '
            .'El `context.id` del webhook apunta al recordatorio y `notification_logs` '
            .'lo ata a este turno: no hace falta el estado de la conversación para resolverlo.');
    }

    /**
     * El cliente tiene **dos turnos abiertos** y confirma el primero.
     *
     * Sin usar el `context.id`, la respuesta se resolvería contra "el turno del
     * cliente" y confirmaría el que no era — o los dos.
     */
    public function test_con_dos_turnos_abiertos_se_confirma_el_del_recordatorio_tocado(): void
    {
        $this->fakes();

        $arranque = CarbonImmutable::now();
        $conversacion = $this->conversacion();

        $primero = $this->turno($arranque->addHours(24), eventId: 'evt_primero', conversacion: $conversacion);
        $segundo = $this->turno($arranque->addHours(26), eventId: 'evt_segundo', conversacion: $conversacion);

        // Cada turno entra en su ventana por separado.
        $recordatorioDelPrimero = $this->mandarRecordatorio();

        CarbonImmutable::setTestNow($arranque->addHours(2));
        $this->mandarRecordatorio();

        CarbonImmutable::setTestNow($arranque->addHours(2)->addMinutes(5));
        $this->tocar($recordatorioDelPrimero, self::CONFIRMAR);

        $this->assertSame(Booking::ESTADO_CONFIRMADO, $this->estadoDelTurno($primero),
            'Se confirmó el turno equivocado: el botón tocado era el del primer recordatorio.');
        $this->assertSame(Booking::ESTADO_AGENDADO, $this->estadoDelTurno($segundo),
            'Confirmar un turno confirmó también el otro: el cliente tiene dos turnos '
            .'y solo respondió por uno.');
    }
}
