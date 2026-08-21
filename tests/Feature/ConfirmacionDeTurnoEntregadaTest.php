<?php

namespace Tests\Feature;

use App\Jobs\ProcessMessageJob;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Si el turno quedó agendado, el cliente se entera.
 *
 * El turno se crea en Google, se persiste en `bookings`, y **después** se manda
 * la confirmación. Todo lo que se caiga en el medio produce el mismo daño: el
 * turno existe, el cliente no lo sabe, no va, y la PyME tiene el horario
 * bloqueado por nadie.
 *
 * El invariante que estos tests fijan:
 *
 * > **Todo turno persistido en `bookings` tiene su confirmación entregada al
 * > cliente — o, si no se pudo entregar, queda registrada como no entregada.**
 *
 * ⚠️ **Dónde queda registrada es una decisión que el criterio no toma.** Acá se
 * afirma sobre `notification_logs`, que existe justamente para eso: su DDL
 * declara `type = 'confirmation'` y `status = 'failed'`, y su único
 * `(booking_id, type)` la ata al turno. Una línea de log no sirve para el mismo
 * propósito: no se puede preguntar *"¿qué turnos quedaron sin avisar?"*, que es
 * lo que alguien va a necesitar hacer.
 */
class ConfirmacionDeTurnoEntregadaTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '5493764278402';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 08:00', self::TZ));
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

    // ------------------------------------------------------------- utilidades

    /**
     * Un solo `Http::fake()` por test.
     *
     * ⚠️ `Http::fake()` **acumula** stubs: re-fakear a mitad de camino para
     * simular el fallo dejaría ganando al doble feliz y el test pasaría sin
     * haber probado nada.
     *
     * @param  mixed  $meta  Respuesta o secuencia para `graph.facebook.com`.
     * @param  mixed  $alCrearEvento  Respuesta o closure del POST a Google.
     */
    private function fakes($meta = null, $alCrearEvento = null): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => $alCrearEvento
                ?? Http::response(['id' => 'evt_google_123']),
            'graph.facebook.com/*' => $meta
                ?? Http::response(['messages' => [['id' => 'wamid.OUT_'.uniqid()]]]),
        ]);
    }

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

    /** Lo que Meta **aceptó**: lo que el cliente realmente vio en el chat. */
    private function entregadosAlCliente(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com') && $res->successful()) {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    /** @return array<int,int> */
    private function respuestasDeMeta(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com')) {
                $out[] = $res->status();
            }
        }

        return $out;
    }

    private function textoEntregadoQueContiene(string $fragmento): ?string
    {
        foreach ($this->entregadosAlCliente() as $m) {
            $cuerpo = $m['text']['body'] ?? null;

            if (is_string($cuerpo) && str_contains($cuerpo, $fragmento)) {
                return $cuerpo;
            }
        }

        return null;
    }

    /** Recorre el flujo hasta la lista y devuelve el `id` del primer horario. */
    private function llegarHastaLaLista(string $nombre = 'María'): string
    {
        (new ProcessMessageJob($this->texto('Hola')))->handle();

        $bienvenida = $this->entregadosAlCliente()[0];
        $reservar = $bienvenida['interactive']['action']['buttons'][0]['reply']['id'];

        (new ProcessMessageJob($this->toca($reservar)))->handle();
        (new ProcessMessageJob($this->texto($nombre)))->handle();

        foreach ($this->entregadosAlCliente() as $m) {
            if (($m['interactive']['type'] ?? null) === 'list') {
                return $m['interactive']['action']['sections'][0]['rows'][0]['id'];
            }
        }

        $this->fail('Nunca se ofreció la lista de horarios.');
    }

    private function turno(): ?Booking
    {
        return TenantContext::runAs($this->tenant->id, fn () => Booking::first());
    }

    /** El registro de la confirmación de ese turno, si existe. */
    private function registroDeConfirmacion(Booking $booking): ?object
    {
        return DB::table('notification_logs')
            ->where('booking_id', $booking->id)
            ->where('type', 'confirmation')
            ->first();
    }

    // ------------------------------------------- el turno se confirma y consta

    /**
     * Camino feliz: el turno existe, el cliente lo recibió, y consta.
     *
     * La primera mitad ya la cubre T-026. Lo que se agrega es la segunda: que
     * **quede registro por turno** de que la confirmación salió. Sin eso, el
     * caso en que no sale no se puede distinguir del caso en que sí.
     */
    public function test_un_turno_agendado_deja_su_confirmacion_registrada_como_entregada(): void
    {
        $this->fakes();

        (new ProcessMessageJob($this->toca($this->llegarHastaLaLista())))->handle();

        $booking = $this->turno();
        $this->assertNotNull($booking, 'No se persistió el turno.');

        $this->assertNotNull($this->textoEntregadoQueContiene('quedó reservado'),
            'El turno existe y el cliente no recibió la confirmación.');

        $registro = $this->registroDeConfirmacion($booking);

        $this->assertNotNull($registro,
            'El turno no tiene registro de su confirmación: no hay forma de preguntar '
            .'después qué turnos quedaron sin avisar.');
        $this->assertContains($registro->status, ['sent', 'delivered', 'read'],
            'La confirmación salió y quedó registrada como no entregada.');
        $this->assertNotNull($registro->whatsapp_message_id,
            'El registro no guarda el id del mensaje: no se puede cruzar con los webhooks de estado.');
        // `tenant_id` es UUID: se compara como string, nunca casteado a entero.
        $this->assertSame($this->tenant->id, (string) $registro->tenant_id);
    }

    // ---------------------------- la transición choca después de crear el turno

    /**
     * Otro worker toma la conversación **justo después de crear el turno**.
     *
     * `ConversacionOcupada` hoy se atrapa y el mensaje se descarta entero. El
     * problema es dónde ocurre: el turno **ya está creado en Google y en
     * `bookings`**, y lo que se descarta es la única confirmación que el cliente
     * iba a recibir.
     *
     * La colisión se monta tomando el lock de la conversación mientras Google
     * crea el evento — que es exactamente la ventana en la que ocurre en
     * producción, entre `SLOT_SELECTED` y `BOOKED`.
     */
    public function test_si_la_transicion_choca_despues_de_crear_el_turno_la_confirmacion_sale_igual(): void
    {
        $lockDeOtroWorker = null;

        $this->fakes(alCrearEvento: function () use (&$lockDeOtroWorker) {
            $lockDeOtroWorker = Cache::lock(
                "fsm:tenant:{$this->tenant->id}:phone:".self::TELEFONO, 30
            );
            $lockDeOtroWorker->get();

            return Http::response(['id' => 'evt_google_123']);
        });

        $idHorario = $this->llegarHastaLaLista();

        try {
            (new ProcessMessageJob($this->toca($idHorario)))->handle();
        } finally {
            $lockDeOtroWorker?->release();
        }

        $booking = $this->turno();

        $this->assertNotNull($booking,
            'El turno no se creó: sin turno persistido, este test no prueba lo que dice probar.');

        $this->assertNotNull($this->textoEntregadoQueContiene('quedó reservado'),
            'El turno quedó agendado y bloqueando el horario, y el cliente nunca se enteró: '
            .'no va a ir, y la PyME tiene ese horario perdido.');
    }

    /**
     * La otra mitad del mismo choque: si igual no salió, tiene que constar.
     *
     * Es el invariante puro — entregada **o** registrada como no entregada—,
     * escrito de forma que no obligue a un mecanismo en particular.
     */
    public function test_si_la_transicion_choca_el_turno_no_queda_sin_confirmacion_ni_registro(): void
    {
        $lockDeOtroWorker = null;

        $this->fakes(alCrearEvento: function () use (&$lockDeOtroWorker) {
            $lockDeOtroWorker = Cache::lock(
                "fsm:tenant:{$this->tenant->id}:phone:".self::TELEFONO, 30
            );
            $lockDeOtroWorker->get();

            return Http::response(['id' => 'evt_google_123']);
        });

        $idHorario = $this->llegarHastaLaLista();

        try {
            (new ProcessMessageJob($this->toca($idHorario)))->handle();
        } finally {
            $lockDeOtroWorker?->release();
        }

        $booking = $this->turno();
        $this->assertNotNull($booking, 'El turno no se creó.');

        $entregada = $this->textoEntregadoQueContiene('quedó reservado') !== null;

        $this->assertTrue(
            $entregada || $this->registroDeConfirmacion($booking) !== null,
            'El turno existe, la confirmación no salió y no quedó rastro de que no salió: '
            .'se perdió en silencio.',
        );
    }

    // ------------------------------- Meta rechaza la confirmación (no es 429)

    /**
     * Meta rechaza la confirmación con un error que **no** es rate limit.
     *
     * El envío devuelve `null` en vez de lanzar —a propósito: reintentar un
     * `131047` daría lo mismo tres veces—, y **nadie mira ese retorno**. El turno
     * queda agendado y el único aviso al cliente se evaporó sin que el turno
     * sepa nada.
     *
     * La secuencia deja pasar la bienvenida, la pregunta por el nombre y la
     * lista, y rechaza de la cuarta en adelante: la confirmación.
     */
    public function test_si_meta_rechaza_la_confirmacion_el_turno_queda_registrado_como_no_entregado(): void
    {
        $this->fakes(Http::sequence()
            ->push(['messages' => [['id' => 'wamid.OUT_1']]])
            ->push(['messages' => [['id' => 'wamid.OUT_2']]])
            ->push(['messages' => [['id' => 'wamid.OUT_3']]])
            // 131047 · "Re-engagement message": rechazo definitivo, no rate limit.
            ->whenEmpty(Http::response(['error' => ['code' => 131047]], 400)));

        $idHorario = $this->llegarHastaLaLista();

        (new ProcessMessageJob($this->toca($idHorario)))->handle();

        $this->assertContains(400, $this->respuestasDeMeta(),
            'El doble que rechaza la confirmación nunca se consumió: el test no probó ningún rechazo.');

        $booking = $this->turno();
        $this->assertNotNull($booking, 'No se persistió el turno.');

        $this->assertNull($this->textoEntregadoQueContiene('quedó reservado'),
            'La confirmación llegó igual: el escenario no es el que se quería probar.');

        $registro = $this->registroDeConfirmacion($booking);

        $this->assertNotNull($registro,
            'Meta rechazó la confirmación y el turno no tiene ningún registro de eso: '
            .'el cliente no sabe que tiene turno y nadie puede listar los turnos sin avisar.');
        $this->assertSame('failed', $registro->status,
            'La confirmación no salió y el registro no dice que no salió.');
        // `tenant_id` es UUID: se compara como string, nunca casteado a entero.
        $this->assertSame($this->tenant->id, (string) $registro->tenant_id);
    }

    /**
     * El registro es **por turno**, y no se lo lleva otro tenant.
     *
     * RNF-01 · Si el registro del no entregado se escribiera sin `tenant_id`
     * —o con uno casteado—, la lista de turnos sin avisar de una PyME mostraría
     * los de otra.
     */
    public function test_el_registro_del_no_entregado_pertenece_al_tenant_del_turno(): void
    {
        $otro = Tenant::create([
            'name' => 'Consultorio Norte', 'slug' => 'otro',
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $this->fakes(Http::sequence()
            ->push(['messages' => [['id' => 'wamid.OUT_1']]])
            ->push(['messages' => [['id' => 'wamid.OUT_2']]])
            ->push(['messages' => [['id' => 'wamid.OUT_3']]])
            ->whenEmpty(Http::response(['error' => ['code' => 131047]], 400)));

        (new ProcessMessageJob($this->toca($this->llegarHastaLaLista())))->handle();

        $booking = $this->turno();
        $this->assertNotNull($booking, 'No se persistió el turno.');

        $registro = $this->registroDeConfirmacion($booking);
        $this->assertNotNull($registro, 'No hay registro de la confirmación no entregada.');

        $this->assertSame($this->tenant->id, (string) $registro->tenant_id);
        $this->assertNotSame($otro->id, (string) $registro->tenant_id,
            'El registro del turno de una PyME quedó bajo otra.');
        $this->assertSame(0, DB::table('notification_logs')->where('tenant_id', $otro->id)->count());
    }
}
