<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Conversacion\Fallback;
use App\Jobs\ProcessMessageJob;
use App\Meta\MetaAdapter;
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
 * T-033 · Fallback graceful ante caídas de terceros.
 *
 * **El criterio que manda es AC-09.4**: ningún camino del worker termina en
 * silencio. Los otros tres son los modos de falla concretos que lo ponen a
 * prueba — Google caído, credenciales revocadas, Meta limitándonos.
 *
 * Los tests recorren el **flujo real** (`ProcessMessageJob`) y no los servicios
 * sueltos: la garantía es sobre lo que el cliente recibe en el chat, y un
 * servicio que devuelve el resultado correcto sin que nadie lo mande no cumple
 * nada.
 */
class FallbackTercerosTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '5493764278402';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    private Integration $meta;

    private Integration $google;

    /** @var array<int,array<string,mixed>> */
    private array $registros = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00', self::TZ));
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

        TenantContext::set($this->tenant->id);

        // Se escucha todo el log una sola vez: cada test filtra por `codigo`.
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

    private function config(): BusinessSetting
    {
        return BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)->firstOrFail();
    }

    /** Texto de cortesía propio del tenant, para probar que no sale uno genérico. */
    private function cortesiaPropia(): string
    {
        $config = $this->config();
        $config->fallback_message = 'Uy, se nos cayó el sistema. Te escribimos en un rato.';
        $config->save();

        return $config->fallback_message;
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
    private function payloadDeTexto(string $texto): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
                'messages' => [[
                    'from' => self::TELEFONO, 'id' => 'wamid.'.uniqid('', true),
                    'type' => 'text', 'text' => ['body' => $texto],
                ]],
            ],
        ]]]]];
    }

    /** Cuerpos que efectivamente salieron hacia Meta. @return array<int,array<string,mixed>> */
    private function aMeta(): array
    {
        $out = [];
        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com')) {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    private function ultimoTextoAlCliente(): ?string
    {
        $enviados = $this->aMeta();
        $ultimo = end($enviados);

        return $ultimo === false ? null : ($ultimo['text']['body'] ?? null);
    }

    /** @return array<int,array<string,mixed>> */
    private function conCodigo(string $codigo): array
    {
        return array_values(array_filter(
            $this->registros,
            fn (array $r) => ($r['contexto']['codigo'] ?? null) === $codigo,
        ));
    }

    // ------------------------------------------------- AC-09.1 · Google en 5xx

    /**
     * AC-09.1 · Google devuelve `5xx` → cortesía del tenant y excepción registrada.
     *
     * Se recorre el flujo entero: el cliente escribe su nombre, el bot va a
     * buscar horarios y `freeBusy` se cae. Lo que se mide es lo que le llega al
     * chat, no lo que devuelve el servicio.
     */
    public function test_google_en_5xx_manda_la_cortesia_del_tenant_y_registra_la_excepcion(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response('Backend Error', 503),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]]),
        ]);

        $cortesia = $this->cortesiaPropia();
        $this->conversacion(Estado::GatheringParams, ['esperando' => 'nombre']);

        (new ProcessMessageJob($this->payloadDeTexto('María')))->handle();

        $this->assertSame($cortesia, $this->ultimoTextoAlCliente(),
            'El cliente no recibió el texto de cortesía del tenant con Google caído.');

        $fallos = $this->conCodigo('FREEBUSY_FALLIDO');
        $this->assertNotEmpty($fallos, 'La caída de Google no quedó registrada.');
        $this->assertSame($this->tenant->id, $fallos[0]['contexto']['tenant_id']);
        $this->assertSame('google_calendar', $fallos[0]['contexto']['integracion']);
        $this->assertSame(503, $fallos[0]['contexto']['http']);
    }

    /**
     * AC-09.1 · La parte de "timestamp" del criterio.
     *
     * El llamador no pasa la hora y no tiene que hacerlo: la pone el canal
     * estructurado de T-020. Se verifica sobre la línea escrita, no sobre el
     * evento en memoria — el `MessageLogged` de Laravel no lleva `datetime`, así
     * que asserta ahí probaría algo distinto de lo que el criterio pide.
     */
    public function test_la_excepcion_registrada_lleva_timestamp(): void
    {
        Http::fake();

        $archivo = storage_path('logs/t033-'.uniqid().'.log');
        config()->set('logging.channels.structured.driver', 'single');
        config()->set('logging.channels.structured.path', $archivo);

        Log::channel('structured')->error('freeBusy no respondió', [
            'integracion' => 'google_calendar', 'codigo' => 'FREEBUSY_FALLIDO',
        ]);

        // `daily` parte el nombre por fecha; con `single` el archivo es el que se pidió.
        $this->assertFileExists($archivo);
        $linea = json_decode(trim((string) file_get_contents($archivo)), true);

        try {
            $this->assertArrayHasKey('datetime', $linea, 'La entrada del log no lleva timestamp.');
            $this->assertNotFalse(strtotime((string) $linea['datetime']));
            $this->assertSame('google_calendar', $linea['extra']['integracion'] ?? null);
        } finally {
            @unlink($archivo);
        }
    }

    // ------------------------------------ AC-09.2 · refresh_token revocado

    /**
     * AC-09.2 · `invalid_grant` → fallback al cliente **y** integración marcada.
     *
     * Las dos mitades importan por separado: sin el mensaje el chat queda mudo,
     * y sin la marca el panel de T-034 muestra la integración sana mientras el
     * bot no puede agendar.
     */
    public function test_con_el_refresh_token_revocado_avisa_al_cliente_y_marca_la_integracion(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]]),
        ]);

        $cortesia = $this->cortesiaPropia();

        // Vencido: fuerza la renovación proactiva, que es donde se descubre.
        $this->google->forceFill(['expires_at' => CarbonImmutable::parse('2026-08-24 09:00', 'UTC')])->save();
        $this->conversacion(Estado::GatheringParams, ['esperando' => 'nombre']);

        (new ProcessMessageJob($this->payloadDeTexto('María')))->handle();

        $this->assertSame($cortesia, $this->ultimoTextoAlCliente(),
            'Con el permiso revocado el cliente se quedó sin respuesta.');

        $this->assertSame('expired', $this->google->fresh()->status,
            'La integración quedó marcada como sana con el permiso revocado.');

        $vencidas = $this->conCodigo('OAUTH_INTEGRACION_VENCIDA');
        $this->assertNotEmpty($vencidas);
        $this->assertSame('google_calendar', $vencidas[0]['contexto']['integracion']);
    }

    /**
     * Un fallo **transitorio** de Google no marca la integración.
     *
     * Es la otra mitad del criterio: marcar `expired` ante un 500 de treinta
     * segundos dejaría al cliente desconectado hasta que alguien lo note.
     */
    public function test_un_fallo_transitorio_de_google_no_marca_la_integracion(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response('Internal Error', 500),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]]),
        ]);

        $this->google->forceFill(['expires_at' => CarbonImmutable::parse('2026-08-24 09:00', 'UTC')])->save();
        $this->conversacion(Estado::GatheringParams, ['esperando' => 'nombre']);

        try {
            (new ProcessMessageJob($this->payloadDeTexto('María')))->handle();
        } catch (\RuntimeException) {
            // Se propaga a propósito: es lo que hace que la cola reintente.
        }

        $this->assertSame('connected', $this->google->fresh()->status,
            'Un 500 de treinta segundos dejó la integración marcada como vencida.');
    }

    // -------------------------------------- AC-09.3 · rate limit de Meta

    /**
     * AC-09.3 · Un `429` se lanza para que la cola reintente.
     *
     * Devolver `null` como el resto de los rechazos dejaría al cliente sin el
     * mensaje y sin nadie mirando: la cola nunca se enteraría de que hay algo
     * que volver a intentar.
     */
    public function test_un_429_de_meta_se_lanza_para_que_la_cola_reintente(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 4]], 429)]);

        $c = $this->conversacion(Estado::Idle);

        $this->expectException(MetaRateLimit::class);

        (new MetaAdapter($this->meta))->enviarTexto($c, 'hola');
    }

    /** AC-09.3 · Los dos códigos de rate limit de Meta llegan con HTTP 400. */
    public function test_los_codigos_130429_y_131048_tambien_son_rate_limit(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['code' => 130429]], 400)
            ->push(['error' => ['code' => 131048]], 400),
        ]);

        $c = $this->conversacion(Estado::Idle);
        $adapter = new MetaAdapter($this->meta);

        foreach ([130429, 131048] as $codigo) {
            try {
                $adapter->enviarTexto($c, 'hola');
                $this->fail("El código {$codigo} no se trató como rate limit.");
            } catch (MetaRateLimit $e) {
                $this->assertSame($codigo, $e->codigoDeMeta);
                $this->assertSame($this->tenant->id, $e->tenantId);
            }
        }
    }

    /**
     * AC-09.3 · **Solo al agotar reintentos** se registra el fallo definitivo.
     *
     * Hoy un `ERROR` por intento metía tres entradas por cada mensaje que
     * después salía bien, y ése es justo el ruido que T-039 va a tener que
     * filtrar para contar los envíos que de verdad se perdieron.
     */
    public function test_el_primer_rate_limit_no_registra_el_fallo_definitivo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 130429]], 400)]);

        $c = $this->conversacion(Estado::Idle);

        try {
            (new MetaAdapter($this->meta))->enviarTexto($c, 'hola');
        } catch (MetaRateLimit) {
            // Esperado.
        }

        $this->assertEmpty($this->conCodigo('META_ENVIO_RECHAZADO'),
            'Un rate limit reintentable se registró como fallo definitivo.');

        $limites = $this->conCodigo('META_RATE_LIMIT');
        $this->assertCount(1, $limites);
        $this->assertSame('warning', $limites[0]['nivel'],
            'El rate limit se contó como error y no como reintento.');
    }

    /** AC-09.3 · El fallo definitivo se registra recién cuando el job se rinde. */
    public function test_al_agotar_reintentos_se_registra_el_fallo_definitivo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $this->conversacion(Estado::SelectingSlot);

        (new ProcessMessageJob($this->payloadDeTexto('María')))
            ->failed(new MetaRateLimit($this->tenant->id, 429, 130429));

        $definitivos = $this->conCodigo('WORKER_FALLO_DEFINITIVO');
        $this->assertCount(1, $definitivos, 'El fallo definitivo no quedó registrado.');
        $this->assertSame('error', $definitivos[0]['nivel']);
        $this->assertSame($this->tenant->id, $definitivos[0]['contexto']['tenant_id']);
        $this->assertSame(MetaRateLimit::class, $definitivos[0]['contexto']['causa']);
    }

    /** El reintento es el estándar de la cola (T-011): el jitter fino está diferido. */
    public function test_el_reintento_usa_el_backoff_de_la_cola(): void
    {
        $job = new ProcessMessageJob([]);

        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 15], $job->backoff());
    }

    /** Un rechazo que **no** es rate limit no se reintenta: tres veces daría lo mismo. */
    public function test_un_rechazo_que_no_es_rate_limit_no_se_lanza(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131009]], 400)]);

        $c = $this->conversacion(Estado::Idle);

        $this->assertNull((new MetaAdapter($this->meta))->enviarTexto($c, 'hola'));
        $this->assertNotEmpty($this->conCodigo('META_ENVIO_RECHAZADO'));
        $this->assertEmpty($this->conCodigo('META_RATE_LIMIT'));
    }

    // ------------------------------ AC-09.4 · ningún camino termina en silencio

    /**
     * AC-09.4 · El job se rindió: el cliente recibe el mensaje igual.
     *
     * Antes de esto, agotar los tres intentos dejaba la fila en `failed_jobs` y
     * al cliente mirando un chat mudo.
     */
    public function test_al_rendirse_el_worker_el_cliente_recibe_la_cortesia(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $cortesia = $this->cortesiaPropia();
        $c = $this->conversacion(Estado::SlotSelected, ['nombre' => 'María', 'horario' => 'x']);

        (new ProcessMessageJob($this->payloadDeTexto('María')))->failed(new \RuntimeException('boom'));

        $this->assertSame($cortesia, $this->ultimoTextoAlCliente(),
            'El worker se rindió y el chat quedó mudo.');

        $fresca = $c->fresh();
        $this->assertSame('IDLE', $fresca->current_state);
        $this->assertSame([], $fresca->context_data ?? [],
            'Quedó el horario de un flujo que se rompió.');
    }

    /**
     * AC-09.4 · Con el turno ya agendado **se avisa igual**, pero no se lo toca.
     *
     * T-018c decide que un turno agendado no se toca, y eso sigue valiendo: no
     * se borra ni cambia de estado. Pero el job murió, así que el cliente quedó
     * esperando una confirmación que nunca salió — callarse ahí es el silencio
     * que este criterio prohíbe.
     */
    public function test_con_el_turno_agendado_avisa_pero_no_mueve_el_estado(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $c = $this->conversacion(Estado::Booked);

        (new ProcessMessageJob($this->payloadDeTexto('gracias')))->failed(new \RuntimeException('boom'));

        $this->assertCount(1, $this->aMeta(), 'El cliente no recibió nada con el turno ya agendado.');
        $this->assertSame('BOOKED', $c->fresh()->current_state,
            'Un fallo posterior movió de estado un turno real.');
    }

    /**
     * AC-09.4 · El camino mudo que faltaba: un fallo que ningún `catch` preveía.
     *
     * Un 500 en el refresco del token de Google sube como `RuntimeException`
     * genérica, y ni `ofrecerHorarios` ni `agendar` la atrapan. Hasta hoy eso
     * terminaba en `failed_jobs` sin que el cliente supiera nada.
     */
    public function test_un_fallo_no_previsto_de_google_no_deja_al_cliente_mudo(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response('Internal Error', 500),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]]),
        ]);

        $cortesia = $this->cortesiaPropia();
        $this->google->forceFill(['expires_at' => CarbonImmutable::parse('2026-08-24 09:00', 'UTC')])->save();
        $c = $this->conversacion(Estado::GatheringParams, ['esperando' => 'nombre']);

        $job = new ProcessMessageJob($this->payloadDeTexto('María'));
        $excepcion = null;

        try {
            $job->handle();
        } catch (\Throwable $e) {
            $excepcion = $e;
        }

        $this->assertNotNull($excepcion,
            'El fallo se tragó en silencio: la cola nunca se entera de que hay que reintentar.');
        $this->assertSame(0, count($this->aMeta()),
            'Se le avisó al cliente en el primer intento, gastando los reintentos que podían salvarlo.');

        // Se agotaron los tres intentos.
        $job->failed($excepcion);

        $this->assertSame($cortesia, $this->ultimoTextoAlCliente(),
            'Un fallo que ningún catch previó dejó el chat mudo.');
        $this->assertSame('IDLE', $c->fresh()->current_state);
    }

    /**
     * AC-09.4 · La bienvenida sin configuración era un `return` seco.
     *
     * El primer contacto del cliente con el producto terminaba en silencio por
     * un bug nuestro. No se le inventa una bienvenida con datos que no tenemos,
     * pero algo tiene que recibir.
     */
    public function test_la_bienvenida_sin_configuracion_no_deja_al_cliente_mudo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        BusinessSetting::withoutTenantScope()->where('tenant_id', $this->tenant->id)->delete();

        (new ProcessMessageJob($this->payloadDeTexto('Hola')))->handle();

        $this->assertSame(
            BusinessSetting::valoresPorDefecto()['fallback_message'],
            $this->ultimoTextoAlCliente(),
            'Un tenant sin configuración dejaba al cliente sin ninguna respuesta.',
        );
        $this->assertNotEmpty($this->conCodigo('CONFIG_AUSENTE'));
    }

    /**
     * El manejador de fallos **nunca lanza**, ni con Meta también caído.
     *
     * Si lanzara, Laravel registraría el fallo del manejador de fallos y perdería
     * la excepción original, que es la que explica qué pasó. Y el único silencio
     * irreducible —el canal por el que hablamos está caído— queda registrado con
     * código propio.
     */
    public function test_con_meta_tambien_caida_no_lanza_y_deja_registro_del_silencio(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 130429]], 429)]);

        $this->conversacion(Estado::SelectingSlot);

        (new ProcessMessageJob($this->payloadDeTexto('María')))->failed(new \RuntimeException('boom'));

        $this->assertNotEmpty($this->conCodigo('CLIENTE_SIN_AVISO'),
            'El chat quedó mudo sin que quedara rastro de que no se pudo avisar.');
    }

    /** Un job fallido de un número que no mapea a ningún tenant no rompe nada. */
    public function test_un_job_fallido_sin_integracion_no_rompe_el_manejador(): void
    {
        Http::fake();

        $payload = $this->payloadDeTexto('hola');
        $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '999';

        (new ProcessMessageJob($payload))->failed(new \RuntimeException('boom'));

        $this->assertNotEmpty($this->conCodigo('CLIENTE_SIN_AVISO'));
        $this->assertEmpty($this->conCodigo('WORKER_FAILED_HANDLER_ROTO'));
    }

    /**
     * Un aviso por persona, no por mensaje.
     *
     * Si el cliente mandó tres seguidos y el job murió, tres disculpas idénticas
     * son peores que una.
     */
    public function test_avisa_una_sola_vez_aunque_el_payload_traiga_varios_mensajes(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $this->conversacion(Estado::SelectingSlot);

        $payload = $this->payloadDeTexto('uno');
        $payload['entry'][0]['changes'][0]['value']['messages'][] = [
            'from' => self::TELEFONO, 'id' => 'wamid.'.uniqid('', true),
            'type' => 'text', 'text' => ['body' => 'dos'],
        ];

        (new ProcessMessageJob($payload))->failed(new \RuntimeException('boom'));

        $this->assertCount(1, $this->aMeta(), 'Se mandaron disculpas repetidas.');
    }

    /**
     * Desde `ERROR_FALLBACK` se cierra, no se traba.
     *
     * Es el caso en que el job murió justo entre el aviso y la vuelta a `IDLE`:
     * volver a aplicar `FallaTercero` lo rechazaría la tabla —correctamente— y
     * la conversación quedaría trabada ahí para siempre.
     */
    public function test_desde_error_fallback_la_conversacion_vuelve_a_idle(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $c = $this->conversacion(Estado::ErrorFallback, ['horario' => 'x']);

        (new ProcessMessageJob($this->payloadDeTexto('hola')))->failed(new \RuntimeException('boom'));

        $this->assertSame('IDLE', $c->fresh()->current_state,
            'La conversación quedó trabada en ERROR_FALLBACK.');
        $this->assertEmpty($this->conCodigo('FALLBACK_ESTADO_NO_RECUPERADO'));
    }

    /** `porFalloDefinitivo` usa el texto del tenant, nunca uno hardcodeado (T-017). */
    public function test_el_texto_del_ultimo_recurso_sale_de_la_configuracion_del_tenant(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $config = $this->config();
        $config->fallback_message = 'Perdón, se nos rompió algo. Te llamamos nosotros.';
        $config->save();

        $c = $this->conversacion(Estado::SelectingSlot);

        app(Fallback::class)->porFalloDefinitivo($c, $this->meta, $config->fresh(), 'RuntimeException');

        $this->assertSame('Perdón, se nos rompió algo. Te llamamos nosotros.', $this->ultimoTextoAlCliente());
    }
}
