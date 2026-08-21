<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Jobs\ProcessMessageJob;
use App\Meta\MetaRateLimit;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * RNF-03 · Si el envío a Meta falla, el cliente no queda sin respuesta.
 *
 * El escenario es el que más duele: el cliente escribió su nombre, lo único que
 * falta es la lista de horarios, y **el envío se cae**.
 *
 * Lo que tiene que valer, pase lo que pase con Meta:
 *
 * > **O el cliente termina recibiendo lo que estaba esperando, o corre el camino
 * > de fallo definitivo y recibe el mensaje de cortesía. Nunca las dos ausentes.**
 *
 * Todas las aserciones son sobre **lo que le llega al chat** y sobre si la
 * conversación le deja una salida. Ninguna mira qué método se llamó.
 */
class EntregaTrasFalloDeEnvioTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '5493764278402';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    /** @var array<int,array<string,mixed>> */
    private array $registros = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Lunes a la mañana: hay horarios de sobra que ofrecer.
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

        TenantContext::set($this->tenant->id);

        Log::listen(function ($m) {
            $this->registros[] = ['nivel' => $m->level, 'contexto' => $m->context];
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        $this->registros = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------- utilidades

    /**
     * Un solo `Http::fake()` por test, y el fallo va **dentro** de él.
     *
     * ⚠️ `Http::fake()` acumula stubs: un segundo llamado no reemplaza al
     * primero, y el doble del error nunca se usaría. Por eso el fallo se declara
     * como secuencia acá y no re-fakeando a mitad de camino.
     *
     * @param  mixed  $meta  Respuesta o secuencia para `graph.facebook.com`.
     */
    private function fakes($meta): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),
            'graph.facebook.com/*' => $meta,
        ]);
    }

    private function conversacion(Estado $estado, array $contexto = []): Conversation
    {
        return Conversation::create([
            'user_phone' => self::TELEFONO,
            'current_state' => $estado->value,
            'context_data' => $contexto,
            'last_interaction_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function payloadDeTexto(string $texto, string $wamid = 'wamid.IN_1'): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
                'messages' => [[
                    'from' => self::TELEFONO, 'id' => $wamid,
                    'type' => 'text', 'text' => ['body' => $texto],
                ]],
            ],
        ]]]]];
    }

    /** Los cuerpos que Meta **aceptó**: lo que el cliente realmente vio. */
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

    /** Los códigos HTTP con que Meta respondió, en orden. @return array<int,int> */
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

    /** La lista de horarios, si alguna llegó al cliente. */
    private function listaEntregada(): ?array
    {
        foreach (array_reverse($this->entregadosAlCliente()) as $m) {
            if (($m['interactive']['type'] ?? null) === 'list') {
                return $m;
            }
        }

        return null;
    }

    private function ultimoTextoEntregado(): ?string
    {
        foreach (array_reverse($this->entregadosAlCliente()) as $m) {
            if (($m['type'] ?? null) === 'text') {
                return $m['text']['body'];
            }
        }

        return null;
    }

    private function cortesia(): string
    {
        return BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)->firstOrFail()->fallback_message;
    }

    private function fresca(): Conversation
    {
        return Conversation::where('user_phone', self::TELEFONO)->firstOrFail();
    }

    /** Cierra la trampa conocida: el doble del error se tiene que haber usado. */
    private function assertElFalloSeSimuloDeVerdad(): void
    {
        $this->assertContains(429, $this->respuestasDeMeta(),
            'El doble que simula el fallo de Meta nunca se consumió: el test no probó ningún fallo.');
    }

    // ------------------------------------- fallo transitorio: se recupera

    /**
     * Falla el envío de la lista, Meta se recupera, **y el cliente la recibe**.
     *
     * El primer intento se cae con un rate limit y la cola reintrega el mismo
     * mensaje. Al final del reintento el cliente tiene que tener los horarios en
     * la mano: que el job no explote no le sirve de nada.
     */
    public function test_tras_un_rate_limit_el_reintento_le_entrega_la_lista_al_cliente(): void
    {
        $this->fakes(Http::sequence()
            ->push(['error' => ['code' => 130429]], 429)
            ->whenEmpty(Http::response(['messages' => [['id' => 'wamid.OUT']]])));

        $this->conversacion(Estado::GatheringParams, ['esperando' => 'nombre']);

        $job = new ProcessMessageJob($this->payloadDeTexto('María'));

        // Intento 1 · Meta limita el envío. Se propaga para que la cola reintente.
        try {
            $job->handle();
            $this->fail('El rate limit se tragó: la cola nunca se entera de que hay que reintentar.');
        } catch (MetaRateLimit) {
            // Esperado.
        }

        $this->assertElFalloSeSimuloDeVerdad();
        $this->assertSame([], $this->entregadosAlCliente(),
            'El envío falló pero algo llegó igual: el escenario no es el que se quería probar.');

        // Intento 2 · La cola reintrega el mismo mensaje, con Meta ya normalizada.
        $job->handle();

        $this->assertNotNull($this->listaEntregada(),
            'El cliente escribió su nombre, el envío de los horarios falló una vez '
            .'y el reintento lo dejó sin la lista: se quedó esperando algo que nunca va a llegar.');
    }

    /**
     * Y los horarios que llegan **se pueden elegir**.
     *
     * Una lista cuyos botones nacen caducos es la misma ausencia con otra cara:
     * el cliente toca y no pasa nada.
     */
    public function test_los_horarios_entregados_tras_el_reintento_se_pueden_elegir(): void
    {
        $this->fakes(Http::sequence()
            ->push(['error' => ['code' => 130429]], 429)
            ->whenEmpty(Http::response(['messages' => [['id' => 'wamid.OUT']]])));

        $this->conversacion(Estado::GatheringParams, ['esperando' => 'nombre']);

        $job = new ProcessMessageJob($this->payloadDeTexto('María'));

        try {
            $job->handle();
        } catch (MetaRateLimit) {
            // Esperado: es el fallo que dispara el reintento.
        }

        $this->assertElFalloSeSimuloDeVerdad();

        $job->handle();

        $lista = $this->listaEntregada();
        $this->assertNotNull($lista, 'Nunca llegó la lista: no hay horario que tocar.');

        $fila = $lista['interactive']['action']['sections'][0]['rows'][0]['id'];
        $entregadosAntes = count($this->entregadosAlCliente());

        (new ProcessMessageJob($this->payloadDeEleccion($fila)))->handle();

        $this->assertGreaterThan($entregadosAntes, count($this->entregadosAlCliente()),
            'El cliente tocó un horario de la lista que le acaba de llegar y el bot no le contestó nada: '
            .'los botones que recibió ya estaban caducos.');
    }

    /** @return array<string,mixed> */
    private function payloadDeEleccion(string $idFila): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
                'messages' => [[
                    'from' => self::TELEFONO, 'id' => 'wamid.IN_ELIGE',
                    'type' => 'interactive',
                    'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $idFila, 'title' => 'x']],
                ]],
            ],
        ]]]]];
    }

    // -------------------------------------------- la conversación no se traba

    /**
     * Si la lista no salió, el cliente no queda esperando en un paso sin salida.
     *
     * `SELECTING_SLOT` significa *"te mostré los horarios, elegí uno"*. Si el
     * cliente nunca los recibió, todo lo que escriba va a caer en «no te
     * entiendo» hasta que el cron de inactividad lo rescate media hora después.
     */
    public function test_sin_lista_entregada_la_conversacion_no_queda_esperando_una_eleccion(): void
    {
        $this->fakes(Http::sequence()
            ->push(['error' => ['code' => 130429]], 429)
            ->whenEmpty(Http::response(['messages' => [['id' => 'wamid.OUT']]])));

        $this->conversacion(Estado::GatheringParams, ['esperando' => 'nombre']);

        $job = new ProcessMessageJob($this->payloadDeTexto('María'));

        try {
            $job->handle();
        } catch (MetaRateLimit) {
            // Esperado.
        }

        $this->assertElFalloSeSimuloDeVerdad();

        $job->handle();

        $this->assertTrue(
            $this->listaEntregada() !== null
                || $this->fresca()->current_state !== Estado::SelectingSlot->value,
            'La conversación quedó en SELECTING_SLOT sin que el cliente tenga ninguna lista: '
            .'está esperando que elija de un mensaje que nunca le llegó.',
        );
    }

    // ------------------------------------ fallo que agota los reintentos

    /**
     * Si ningún intento le entrega la lista, **tiene que correr el fallo definitivo**.
     *
     * El ciclo de la cola se recorre como lo recorre Laravel: se reintenta
     * mientras el job lance, y `failed()` corre solo si el último intento
     * lanzó. Un intento que **termina sin excepción sin haberle entregado nada
     * al cliente** le dice a la cola que el mensaje se procesó bien — y ahí la
     * red de AC-09.4 no se despliega nunca.
     */
    public function test_si_ningun_intento_entrega_la_lista_corre_el_camino_de_fallo_definitivo(): void
    {
        // Meta limitando todo el tiempo: ningún envío de este mensaje va a salir.
        $this->fakes(Http::response(['error' => ['code' => 130429]], 429));

        $this->conversacion(Estado::GatheringParams, ['esperando' => 'nombre']);

        $job = new ProcessMessageJob($this->payloadDeTexto('María'));
        $ultima = null;

        for ($intento = 1; $intento <= $job->tries; $intento++) {
            try {
                $job->handle();
                $ultima = null;

                break;   // Terminó bien: para la cola, el mensaje está entregado.
            } catch (\Throwable $e) {
                $ultima = $e;
            }
        }

        $this->assertElFalloSeSimuloDeVerdad();
        $this->assertSame([], $this->entregadosAlCliente(),
            'Con Meta limitando todo, algo llegó igual: el escenario no es el que se quería probar.');

        $this->assertNotNull($ultima,
            'Un intento terminó sin error habiendo dejado al cliente sin la lista: '
            .'la cola da el mensaje por entregado, failed() no corre nunca y el cliente '
            .'no recibe ni los horarios ni el mensaje de cortesía.');

        $job->failed($ultima);

        $definitivos = array_values(array_filter(
            $this->registros,
            fn (array $r) => ($r['contexto']['codigo'] ?? null) === 'WORKER_FALLO_DEFINITIVO',
        ));

        $this->assertNotEmpty($definitivos, 'El camino de fallo definitivo no corrió.');
        // `tenant_id` es UUID: se compara como string, nunca casteado a entero.
        $this->assertSame($this->tenant->id, (string) $definitivos[0]['contexto']['tenant_id']);
    }

    /**
     * El mismo agotamiento, pero con el canal ya restablecido para el aviso.
     *
     * Es el caso realista del rate limit: Meta nos frena veinte segundos y
     * afloja. Aunque los horarios ya no salgan, **la cortesía tiene que llegar**:
     * es la mitad de la disyunción que el criterio no deja vacía.
     */
    public function test_al_agotar_los_reintentos_el_cliente_recibe_la_cortesia(): void
    {
        $this->fakes(Http::sequence()
            ->push(['error' => ['code' => 130429]], 429)
            ->push(['error' => ['code' => 130429]], 429)
            ->push(['error' => ['code' => 130429]], 429)
            ->whenEmpty(Http::response(['messages' => [['id' => 'wamid.OUT']]])));

        $this->conversacion(Estado::GatheringParams, ['esperando' => 'nombre']);

        $job = new ProcessMessageJob($this->payloadDeTexto('María'));
        $ultima = null;

        for ($intento = 1; $intento <= $job->tries; $intento++) {
            try {
                $job->handle();
                $ultima = null;

                break;
            } catch (\Throwable $e) {
                $ultima = $e;
            }
        }

        if ($ultima !== null) {
            $job->failed($ultima);
        }

        $this->assertElFalloSeSimuloDeVerdad();

        $this->assertTrue(
            $this->listaEntregada() !== null || $this->ultimoTextoEntregado() === $this->cortesia(),
            'El cliente no recibió ni los horarios ni el mensaje de cortesía: '
            .'el chat quedó mudo, que es exactamente lo que RNF-03 prohíbe.',
        );
    }
}
