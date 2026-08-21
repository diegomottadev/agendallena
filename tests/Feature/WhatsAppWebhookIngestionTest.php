<?php

namespace Tests\Feature;

use App\Jobs\ProcessMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * T-010 · Ingesta asincrona con validacion de firma.
 * Criterios: AC-02.2, cuerpo crudo, y "ninguna logica de negocio en el request".
 *
 * AC-02.1 (50 webhooks concurrentes, p95 < 200 ms) y el arnes de carga quedan
 * fuera por el recorte Pareto del ticket. No se testean aca a proposito.
 */
class WhatsAppWebhookIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/webhooks/whatsapp';

    private const APP_SECRET = 'app-secret-de-prueba-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.meta.app_secret', self::APP_SECRET);
    }

    /**
     * Payload real de Meta, como cadena cruda.
     *
     * Lleva un espacio extra y un acento escapado a proposito: si la firma se
     * validara sobre el JSON reserializado por Laravel en vez de sobre el byte
     * exacto, estos dos detalles la romperian. Es la trampa del tercer criterio.
     */
    private function cuerpoCrudo(string $messageId = 'wamid.HBgLNTQ5MTEx'): string
    {
        return '{"object":"whatsapp_business_account", "entry":[{"id":"1360872979217448",'
            .'"changes":[{"value":{"messaging_product":"whatsapp","metadata":'
            .'{"display_phone_number":"15551547290","phone_number_id":"1053554814514902"},'
            .'"messages":[{"from":"5491133334444","id":"'.$messageId.'","timestamp":"1755620000",'
            .'"text":{"body":"Hola, quería un turno"},"type":"text"}]},'
            .'"field":"messages"}]}]}';
    }

    private function firmar(string $cuerpo, string $secret = self::APP_SECRET): string
    {
        return 'sha256='.hash_hmac('sha256', $cuerpo, $secret);
    }

    private function postFirmado(string $cuerpo, ?string $firma): \Illuminate\Testing\TestResponse
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($firma !== null) {
            $headers['X-Hub-Signature-256'] = $firma;
        }

        return $this->call('POST', self::URL, [], [], [], $this->servidor($headers), $cuerpo);
    }

    /** @param array<string,string> $headers */
    private function servidor(array $headers): array
    {
        $server = [];

        foreach ($headers as $nombre => $valor) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($nombre))] = $valor;
        }

        return $server;
    }

    /** Firma valida: 200 y el payload integro queda encolado. */
    public function test_firma_valida_responde_200_y_encola_el_payload(): void
    {
        Queue::fake();

        $cuerpo = $this->cuerpoCrudo();

        $this->postFirmado($cuerpo, $this->firmar($cuerpo))->assertOk();

        Queue::assertPushed(ProcessMessageJob::class, function (ProcessMessageJob $job) use ($cuerpo) {
            // El payload viaja integro: el job recibe exactamente lo que mando Meta.
            return $job->payload === json_decode($cuerpo, true);
        });
    }

    /** AC-02.2 — firma invalida: 401, no encola nada. */
    public function test_firma_invalida_responde_401_y_no_encola(): void
    {
        Queue::fake();

        $cuerpo = $this->cuerpoCrudo();
        $firmaDeOtroSecreto = $this->firmar($cuerpo, 'secreto-del-atacante');

        $this->postFirmado($cuerpo, $firmaDeOtroSecreto)->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    /** AC-02.2 — el intento fallido deja registro. */
    public function test_firma_invalida_deja_registro_del_intento(): void
    {
        Queue::fake();
        Log::spy();

        $cuerpo = $this->cuerpoCrudo();

        $this->postFirmado($cuerpo, $this->firmar($cuerpo, 'secreto-del-atacante'))
            ->assertUnauthorized();

        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * El log del intento no puede filtrar ni el App Secret ni la firma recibida.
     * Regla del proyecto: ninguna credencial aparece en el log, ni parcialmente.
     */
    public function test_el_registro_del_intento_no_filtra_credenciales(): void
    {
        Queue::fake();

        $capturado = [];
        Log::listen(function ($mensaje) use (&$capturado) {
            $capturado[] = $mensaje->message.' '.json_encode($mensaje->context);
        });

        $cuerpo = $this->cuerpoCrudo();
        $firmaAtacante = $this->firmar($cuerpo, 'secreto-del-atacante');

        $this->postFirmado($cuerpo, $firmaAtacante)->assertUnauthorized();

        $todo = implode("\n", $capturado);

        $this->assertNotEmpty($todo, 'No se registro el intento fallido.');
        $this->assertStringNotContainsString(self::APP_SECRET, $todo);
        $this->assertStringNotContainsString($firmaAtacante, $todo);
        // Tampoco el hash pelado, sin el prefijo `sha256=`.
        $this->assertStringNotContainsString(substr($firmaAtacante, 7), $todo);
    }

    /** Sin cabecera de firma no se procesa nada. */
    public function test_sin_cabecera_de_firma_responde_401(): void
    {
        Queue::fake();

        $this->postFirmado($this->cuerpoCrudo(), null)->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    /** Una cabecera con formato roto no puede colarse ni reventar el endpoint. */
    public function test_cabecera_de_firma_con_formato_invalido_responde_401(): void
    {
        Queue::fake();

        $cuerpo = $this->cuerpoCrudo();

        foreach (['', 'sha256=', 'no-tiene-prefijo', 'sha256=no-es-hex', 'sha1=abcdef'] as $firmaRota) {
            $this->postFirmado($cuerpo, $firmaRota)->assertUnauthorized();
        }

        Queue::assertNothingPushed();
    }

    /**
     * El tercer criterio del ticket: la firma valida sobre el byte exacto.
     *
     * Se firma el cuerpo crudo y se compara contra la firma del mismo JSON
     * reserializado. Si el endpoint parseara antes de validar, la segunda seria
     * la que pasa — y este test lo detecta.
     */
    public function test_la_firma_se_valida_sobre_el_cuerpo_crudo_y_no_sobre_el_json_reserializado(): void
    {
        Queue::fake();

        $cuerpo = $this->cuerpoCrudo();
        $reserializado = json_encode(json_decode($cuerpo, true));

        // Precondicion del test: los dos textos tienen que diferir de verdad.
        $this->assertNotSame($cuerpo, $reserializado);

        // La firma del cuerpo crudo pasa.
        $this->postFirmado($cuerpo, $this->firmar($cuerpo))->assertOk();

        // La firma del reserializado, enviada con el cuerpo crudo, no.
        $this->postFirmado($cuerpo, $this->firmar($reserializado))->assertUnauthorized();
    }

    /**
     * Sin App Secret configurado el endpoint falla cerrado.
     *
     * Es el modo de falla peligroso: un `.env` sin la clave no puede convertir
     * el webhook en un endpoint publico que encola lo que le manden.
     */
    public function test_sin_app_secret_configurado_falla_cerrado(): void
    {
        Queue::fake();
        config()->set('services.meta.app_secret', null);

        $cuerpo = $this->cuerpoCrudo();

        $this->postFirmado($cuerpo, $this->firmar($cuerpo))->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    /** El GET del handshake (T-008) sigue funcionando: la firma no lo alcanza. */
    public function test_el_handshake_get_no_exige_firma(): void
    {
        $tenant = \App\Models\Tenant::create([
            'name' => 'PyME', 'slug' => 'piloto-t010',
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
        \App\Models\Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => \App\Models\Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => '1053554814514902',
            'settings' => ['verify_token' => 'token-t010'],
            'status' => 'connected',
        ]);

        $this->get(self::URL.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'token-t010',
            'hub.challenge' => 'desafio',
        ]))->assertOk();
    }
}
