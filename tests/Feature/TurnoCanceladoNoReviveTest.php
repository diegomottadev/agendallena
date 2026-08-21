<?php

namespace Tests\Feature;

use App\Conversacion\Reserva;
use App\Jobs\ProcessMessageJob;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Un turno cancelado no se le puede presentar al cliente como si estuviera vivo.
 *
 * El criterio, tal como llegó:
 *
 * > Si el cliente reserva las 15:00, después cancela desde el recordatorio, y
 * > más tarde vuelve a pedir las 15:00, **tiene que quedar con un turno de
 * > verdad, o con un mensaje honesto de que no se pudo.** Lo que no puede pasar
 * > es que reciba *«tu turno quedó reservado»* y no haya turno vivo.
 *
 * ## El camino que lo produce
 *
 * `ClaveDeIdempotencia` es determinística a propósito: mismo tenant, misma
 * conversación y mismo instante dan la misma clave. Cuando el cliente vuelve a
 * pedir el horario que canceló, esa clave es **la del turno cancelado**, y su
 * fila sigue en `bookings` con ese `external_event_id`. El `INSERT` choca contra
 * el único `(tenant_id, external_event_id)`, `turnoYaPersistido()` devuelve la
 * fila cancelada, y el flujo la toma por un turno recién agendado.
 *
 * ## ⚠️ Por qué el defecto **no** depende de lo que haga Google
 *
 * La auditoría lo describió pasando por el `409` que Google contesta sobre la
 * lápida de un evento borrado, y `CalendarioDeGoogle` trata ese `409` como
 * éxito. Pero **cuánto dura esa lápida no lo controlamos**, y un test que
 * dependiera de eso estaría probando la nube.
 *
 * Por eso el escenario se corre **con las dos Googles posibles** —la que
 * recuerda el evento borrado y contesta `409`, y la que lo deja recrear— y en
 * las dos se afirma lo mismo. Que las dos fallen es la prueba de que el defecto
 * es nuestro: lo produce la fila cancelada que quedó ocupando la clave, no la
 * respuesta del tercero.
 *
 * ## ⚠️ Lo que estos tests **no** deciden
 *
 * Qué hay que hacer cuando el sistema se topa con la fila cancelada —revivirla,
 * crear otra con clave distinta, o avisarle al cliente que no se pudo— **el
 * criterio no lo dice y acá no se inventa**. Se afirma solo lo que no admite
 * discusión: que no se confirme un turno que no existe, y que una fila
 * `cancelled` no se devuelva como si fuera un turno vivo. Las tres salidas
 * pasan estas afirmaciones; la que hay hoy no.
 */
class TurnoCanceladoNoReviveTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '5493764278402';

    private const TZ = 'America/Argentina/Buenos_Aires';

    /** Lunes a la mañana: el flujo encuentra horarios en la agenda por defecto. */
    private const AHORA = '2026-08-24 08:00';

    private Tenant $tenant;

    private Integration $meta;

    private Integration $google;

    /**
     * El calendario del doble: `id de evento => true`.
     *
     * Es el estado que hace que el doble se parezca a Google. Sin él, el test
     * decidiría cuándo hay conflicto en vez de descubrirlo.
     *
     * @var array<string,bool>
     */
    private array $eventosEnGoogle = [];

    /**
     * Los `id` que estuvieron y se borraron.
     *
     * @var array<string,bool>
     */
    private array $lapidas = [];

    private int $idsInventados = 0;

    private int $salientes = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, self::TZ));
        config()->set('services.meta.phone_number_id', self::PHONE_NUMBER_ID);

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $this->meta = Integration::create([
            'tenant_id' => $this->tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => self::PHONE_NUMBER_ID, 'access_token' => 'meta',
            'settings' => ['verify_token' => 'tok'], 'status' => 'connected',
        ]);

        $this->google = Integration::create([
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

    // ------------------------------------------------------------- los dobles

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: un segundo `fake()` no reemplaza al
     * primero y el doble que simula el conflicto nunca se consume. Ya pasó tres
     * veces en T-026. Por eso Google, con sus tres verbos, entra en un único
     * mapa y se comporta según su propio estado.
     *
     * @param  bool  $recuerdaLosBorrados  Si Google contesta `409` sobre el `id`
     *   de un evento que ya se borró. **Es justamente lo que no sabemos**, así
     *   que el escenario se corre de las dos formas.
     */
    private function fakes(bool $recuerdaLosBorrados): void
    {
        Http::fake([
            /*
             * El horario está libre en las dos vueltas y es fiel al escenario:
             * en la primera todavía no hay nada agendado, y en la segunda el
             * evento se borró al cancelar.
             */
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),

            'www.googleapis.com/calendar/v3/calendars/primary/events*' => function (Request $request) use ($recuerdaLosBorrados) {
                if ($request->method() === 'DELETE') {
                    $id = basename((string) parse_url($request->url(), PHP_URL_PATH));

                    unset($this->eventosEnGoogle[$id]);
                    $this->lapidas[$id] = true;

                    return Http::response([], 204);
                }

                $id = $request->data()['id'] ?? null;

                if ($id === null) {
                    // Sin `id` propio, Google inventa uno distinto por llamada.
                    $id = 'evt_google_'.(++$this->idsInventados);
                }

                $ocupado = isset($this->eventosEnGoogle[$id])
                    || ($recuerdaLosBorrados && isset($this->lapidas[$id]));

                if ($ocupado) {
                    return Http::response([
                        'error' => [
                            'code' => 409,
                            'message' => 'The requested identifier already exists.',
                            'errors' => [['reason' => 'duplicate']],
                        ],
                    ], 409);
                }

                $this->eventosEnGoogle[$id] = true;
                unset($this->lapidas[$id]);

                return Http::response(['id' => $id, 'status' => 'confirmed']);
            },

            'graph.facebook.com/*' => fn () => Http::response([
                'messages' => [['id' => 'wamid.OUT_'.(++$this->salientes)]],
            ]),
        ]);
    }

    // ------------------------------------------------------ webhooks entrantes

    /** @return array<string,mixed> */
    private function texto(string $cuerpo): array
    {
        return $this->payload(['from' => self::TELEFONO, 'id' => 'wamid.'.uniqid(),
            'type' => 'text', 'text' => ['body' => $cuerpo]]);
    }

    /** @return array<string,mixed> */
    private function toca(string $id): array
    {
        return $this->payload(['from' => self::TELEFONO, 'id' => 'wamid.'.uniqid(),
            'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => 'x']]]);
    }

    /** @return array<string,mixed> */
    private function payload(array $mensaje): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID], 'messages' => [$mensaje]],
        ]]]]];
    }

    /**
     * El mensaje que manda Meta cuando el cliente toca un botón del recordatorio.
     *
     * La forma está verificada contra Meta real (T-037): `context.id` apunta al
     * recordatorio que lo originó, y el `payload` lleva el sello de T-019.
     *
     * @return array<string,mixed>
     */
    private function botonDelRecordatorio(string $wamid, string $accion): array
    {
        return [
            'from' => self::TELEFONO,
            'id' => 'wamid.'.uniqid(),
            'type' => 'button',
            'button' => ['text' => 'Cancelar', 'payload' => $accion.'|BOOKED|4'],
            'context' => ['id' => $wamid],
        ];
    }

    // ---------------------------------------------------------- lo que salió

    /**
     * Lo que Meta **aceptó**: lo que el cliente realmente vio en el chat.
     *
     * @return array<int,array<string,mixed>>
     */
    private function entregados(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com') && $res->successful()) {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    /**
     * Los textos entregados a partir del mensaje número `$desde`.
     *
     * @return array<int,string>
     */
    private function textosDesde(int $desde): array
    {
        $out = [];

        foreach (array_slice($this->entregados(), $desde) as $m) {
            if (is_string($m['text']['body'] ?? null)) {
                $out[] = $m['text']['body'];
            }
        }

        return $out;
    }

    /** @param  array<int,string>  $textos */
    private function alguienDijo(array $textos, string $fragmento): bool
    {
        foreach ($textos as $t) {
            if (str_contains($t, $fragmento)) {
                return true;
            }
        }

        return false;
    }

    /** Los cuerpos de todos los `POST` de creación de evento, en orden. */
    private function creacionesEnviadas(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if ($req->method() === 'POST' && str_ends_with($req->url(), '/events')) {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    private function conflictosRecibidos(): int
    {
        $n = 0;

        foreach (Http::recorded() as [$req, $res]) {
            if ($res->status() === 409) {
                $n++;
            }
        }

        return $n;
    }

    // ------------------------------------------------------------- utilidades

    private function config(): BusinessSetting
    {
        return BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->firstOrFail();
    }

    private function conversacion(): Conversation
    {
        return TenantContext::runAs($this->tenant->id, fn () => Conversation::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'user_phone' => self::TELEFONO],
            [
                'current_state' => 'SLOT_SELECTED',
                'state_version' => 3,
                'context_data' => ['nombre' => 'María', 'servicio' => 'Corte de pelo'],
                'last_interaction_at' => now(),
            ]
        ));
    }

    /** Agenda como lo hace `ProcessMessageJob`: dentro del `runAs()` del tenant. */
    private function agendar(Conversation $conversacion, CarbonImmutable $inicio): ?Booking
    {
        return TenantContext::runAs($this->tenant->id, fn () => app(Reserva::class)->agendar(
            $conversacion,
            $this->tenant,
            $this->config(),
            $this->google,
            $inicio,
        ));
    }

    private function turnosVivos(): int
    {
        return TenantContext::runAs($this->tenant->id, fn () => Booking::query()
            ->where('status', '!=', Booking::ESTADO_CANCELADO)
            ->count());
    }

    /**
     * El cliente toca «Cancelar» en el recordatorio de ese turno.
     *
     * Se pasa por el código real de T-037 —el que borra el evento y deja la fila
     * en `cancelled`— y no por un `update()` a mano: el escenario que importa es
     * el que produce el estado, no un estado escrito por el test.
     *
     * El recordatorio se da por enviado con su fila en `notification_logs`, que
     * es lo único que `RespuestaAlRecordatorio` necesita para resolver el
     * `context.id`. Mandarlo de verdad exigiría el alta de la cuenta y las
     * plantillas aprobadas (T-050), que no es lo que se prueba acá.
     */
    private function cancelarDesdeElRecordatorio(Booking $booking): void
    {
        $wamid = 'wamid.RECORDATORIO_'.$booking->id;

        TenantContext::runAs($this->tenant->id, fn () => NotificationLog::create([
            'tenant_id' => $this->tenant->id,
            'booking_id' => $booking->id,
            'type' => NotificationLog::TIPO_RECORDATORIO_24H,
            'whatsapp_message_id' => $wamid,
            'status' => NotificationLog::ESTADO_ENVIADO,
            'sent_at' => now(),
        ]));

        (new ProcessMessageJob($this->payload(
            $this->botonDelRecordatorio($wamid, 'cancelar')
        )))->handle();

        $this->assertSame(Booking::ESTADO_CANCELADO, (string) $booking->fresh()->status,
            'El turno no quedó cancelado: sin eso el escenario no está montado y el test '
            .'no prueba lo que dice probar.');
    }

    /**
     * Recorre el flujo hasta la lista y devuelve el `id` del primer horario.
     *
     * El primer mensaje es parámetro porque la segunda vuelta arranca con la
     * conversación en `BOOKED`: ahí «Hola» no reinicia nada, y el cliente que
     * quiere otro turno usa la salida de emergencia (AC-16.3).
     *
     * @return array{0:string,1:string}  El `id` sellado y el título visible.
     */
    private function llegarHastaLaLista(string $primerMensaje): array
    {
        $antes = count($this->entregados());

        (new ProcessMessageJob($this->texto($primerMensaje)))->handle();

        $bienvenida = $this->entregados()[$antes] ?? null;
        $reservar = $bienvenida['interactive']['action']['buttons'][0]['reply']['id'] ?? null;

        $this->assertNotNull($reservar, 'No llegó la bienvenida con el botón de reservar.');

        (new ProcessMessageJob($this->toca($reservar)))->handle();
        (new ProcessMessageJob($this->texto('María')))->handle();

        foreach (array_reverse(array_slice($this->entregados(), $antes)) as $m) {
            if (($m['interactive']['type'] ?? null) === 'list') {
                $fila = $m['interactive']['action']['sections'][0]['rows'][0];

                return [$fila['id'], $fila['title']];
            }
        }

        $this->fail('Nunca se ofreció la lista de horarios.');
    }

    // ------------------------------ el cliente que cancela y vuelve a pedirlo

    /**
     * El escenario del criterio, con la Google que **recuerda** el borrado.
     *
     * Es el camino que describió la auditoría: el `409` sobre la lápida entra a
     * `CalendarioDeGoogle` como éxito, así que del otro lado no queda evento
     * ninguno —ni el viejo ni uno nuevo—, la fila sigue `cancelled`, y el
     * cliente recibe la confirmación igual. Es el peor de los dos: no hay nada,
     * en ningún lado, y el cliente cree que tiene turno.
     */
    public function test_el_cliente_que_cancela_y_vuelve_a_pedir_el_mismo_horario_no_recibe_una_confirmacion_falsa(): void
    {
        $this->fakes(recuerdaLosBorrados: true);

        $this->correrLaVueltaCompleta();

        // Guarda contra el verde falso: si el `409` nunca ocurrió, este test no
        // ejerció el camino que dice ejercer.
        $this->assertGreaterThan(0, $this->conflictosRecibidos(),
            'Google nunca contestó 409: el doble del conflicto no se consumió y el escenario '
            .'de la lápida no se ejerció.');
    }

    /**
     * El mismo escenario con la Google que **deja recrear** el evento borrado.
     *
     * Acá el `409` no existe, el evento se crea de nuevo, y el defecto ocurre
     * igual: el choque es contra **nuestro** único `(tenant_id,
     * external_event_id)`, que sigue ocupado por la fila cancelada.
     *
     * Que este también falle es lo que prueba que el problema no es cómo
     * tratamos el `409`, sino que una fila `cancelled` se devuelva como turno.
     */
    public function test_lo_mismo_cuando_google_deja_recrear_el_evento_borrado(): void
    {
        $this->fakes(recuerdaLosBorrados: false);

        $this->correrLaVueltaCompleta();

        $this->assertSame(0, $this->conflictosRecibidos(),
            'Con esta Google no tendría que haber ningún 409: si lo hubo, el doble no se '
            .'comportó como se pidió y el test está probando el otro escenario.');
    }

    /**
     * Reserva, cancela desde el recordatorio, y vuelve a pedir el mismo horario.
     *
     * Lo que se afirma al final es el criterio entero y nada más: **turno de
     * verdad, o mensaje honesto**. No se afirma cuál de los dos, porque el
     * criterio no lo decide.
     */
    private function correrLaVueltaCompleta(): void
    {
        // 1 · Reserva.
        [$idHorario, $titulo] = $this->llegarHastaLaLista('Hola');
        (new ProcessMessageJob($this->toca($idHorario)))->handle();

        $booking = TenantContext::runAs($this->tenant->id, fn () => Booking::query()->first());
        $this->assertNotNull($booking, 'No se agendó el primer turno.');

        // 2 · Cancela desde el recordatorio.
        $this->cancelarDesdeElRecordatorio($booking);

        // 3 · Vuelve a pedir **el mismo horario**.
        $desde = count($this->entregados());

        [$idOtraVez, $tituloOtraVez] = $this->llegarHastaLaLista('menu');

        $this->assertSame($titulo, $tituloOtraVez,
            'La segunda vuelta ofreció otro horario: el escenario del criterio es volver a '
            .'pedir el que se canceló.');

        (new ProcessMessageJob($this->toca($idOtraVez)))->handle();

        // Guarda: el segundo pedido tiene que haber llegado hasta Google.
        $this->assertCount(2, $this->creacionesEnviadas(),
            'El segundo agendamiento ni siquiera intentó crear el evento: el test no ejerció '
            .'el camino que dice ejercer.');

        $dichoDespues = $this->textosDesde($desde);
        $confirmo = $this->alguienDijo($dichoDespues, 'quedó reservado');
        $vivos = $this->turnosVivos();

        // El chat no puede quedar mudo: la otra mitad del criterio es que, si no
        // se pudo, el cliente se entere.
        $this->assertNotSame([], $dichoDespues,
            'El cliente pidió el horario y no recibió ninguna respuesta.');

        $this->assertFalse($confirmo && $vivos === 0,
            'El cliente recibió «tu turno quedó reservado» y en `bookings` no hay ningún '
            .'turno vivo: lo único que quedó es la fila que él mismo canceló. No hay turno '
            .'en el panel, no va a haber recordatorio, y el cliente se va a presentar.');
    }

    // ----------------------------------- la fila cancelada, sin intermediarios

    /**
     * **El corazón del defecto.** Una fila `cancelled` no es un turno persistido.
     *
     * Sin flujo ni webhooks: se agenda, se cancela por el camino real, y se
     * vuelve a agendar el mismo horario. El `INSERT` choca contra el único, y lo
     * que hoy se devuelve es la fila que el cliente canceló.
     *
     * ⚠️ **La afirmación deja abiertas las salidas posibles a propósito.** Un
     * `null` —«no se pudo agendar»— la pasa, y una fila revivida a `scheduled`
     * también. Lo único que prohíbe es lo que hay hoy: devolver la cancelada tal
     * cual, que es lo que hace que el flujo confirme un turno que no existe.
     */
    public function test_un_turno_cancelado_no_cuenta_como_turno_ya_persistido(): void
    {
        $this->fakes(recuerdaLosBorrados: true);

        $conversacion = $this->conversacion();
        $inicio = CarbonImmutable::parse('2026-08-25 14:00:00', 'UTC');

        $primero = $this->agendar($conversacion, $inicio);
        $this->assertNotNull($primero, 'El primer intento no agendó nada.');

        $this->cancelarDesdeElRecordatorio($primero);

        $segundo = $this->agendar($conversacion->fresh(), $inicio);

        $this->assertNotSame(Booking::ESTADO_CANCELADO, (string) ($segundo?->status ?? ''),
            'Se devolvió como turno recién agendado la misma fila que el cliente canceló. '
            .'Encontrar una fila cancelada donde se iba a crear una nueva no es «ya estaba '
            .'hecho»: es una situación a resolver, no a confirmar.');

        if ($segundo !== null) {
            // `tenant_id` es UUID: se compara como string. Un `(int)` sobre un
            // UUID devuelve 1 para todos y la afirmación pasaría siempre.
            $this->assertSame($this->tenant->id, (string) $segundo->tenant_id);
        }
    }

    /**
     * La guarda del otro lado: **un turno vivo sí cuenta**.
     *
     * Existe para que esto no se «arregle» rompiendo la idempotencia real. Si un
     * reintento sobre un turno vivo dejara de converger, `Fallback` borraría del
     * calendario un evento que la PyME ya vio y le pediría disculpas al cliente
     * por un turno que quedó bien.
     *
     * ⚠️ **Es la única del archivo que se espera en verde desde el principio**:
     * describe el comportamiento que ya existe (T-030 / AC-22.3) y que este
     * cambio no puede tocar. Se declara acá para no reportarla como un rojo que
     * no fue.
     */
    public function test_un_turno_vivo_si_cuenta_como_turno_ya_persistido(): void
    {
        $this->fakes(recuerdaLosBorrados: true);

        $conversacion = $this->conversacion();
        $inicio = CarbonImmutable::parse('2026-08-25 14:00:00', 'UTC');

        $primero = $this->agendar($conversacion, $inicio);
        $segundo = $this->agendar($conversacion->fresh(), $inicio);

        $this->assertNotNull($primero, 'El primer intento no agendó nada.');
        $this->assertNotNull($segundo,
            'El reintento sobre un turno vivo devolvió «no se pudo agendar»: el fallback le '
            .'borraría a la PyME un evento real.');

        $this->assertSame($primero->id, $segundo->id, 'El reintento no convergió al mismo turno.');
        $this->assertSame(Booking::ESTADO_AGENDADO, (string) $segundo->status);

        $this->assertSame(1, TenantContext::runAs($this->tenant->id, fn () => Booking::query()->count()),
            'El reintento creó una segunda fila para el mismo turno.');

        $this->assertGreaterThan(0, $this->conflictosRecibidos(),
            'Google nunca contestó 409: la clave repetida no se mandó, así que la fila única '
            .'la está dando otra cosa y no la idempotencia.');
    }
}
