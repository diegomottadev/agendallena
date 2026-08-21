<?php

namespace Tests\Feature;

use App\Jobs\ProcessMessageJob;
use App\Models\Integration;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-011 · Idempotencia de la ingesta.
 * Criterios: reentrega del mismo `message_id` sin segunda respuesta, y jobs
 * agotados en `failed_jobs`.
 *
 * AC-02.4 (72 h de piloto sin reintentos en el panel de Meta) no se puede
 * verificar sin trafico real; el propio ticket lo admite.
 */
class WhatsAppIngestionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    private function tenantConIntegracion(string $slug = 'piloto'): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'PyME '.$slug,
            'slug' => $slug,
            'status' => 'active',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => self::PHONE_NUMBER_ID,
            'settings' => ['verify_token' => 'tok-'.$slug],
            'status' => 'connected',
        ]);

        return $tenant;
    }

    /** @return array<string,mixed> */
    private function payload(string $messageId, string $phoneNumberId = self::PHONE_NUMBER_ID): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '1360872979217448',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'from' => '5491133334444',
                            'id' => $messageId,
                            'timestamp' => '1755620000',
                            'type' => 'text',
                            'text' => ['body' => 'Hola, quiero un turno'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /** Una reentrega del mismo message_id no vuelve a procesar. */
    public function test_la_reentrega_del_mismo_mensaje_no_se_procesa_dos_veces(): void
    {
        $tenant = $this->tenantConIntegracion();

        /*
         * Se cuenta la transicion de estado, que es lo que ocurre una sola vez
         * por mensaje realmente procesado. Antes se contaba un log generico que
         * T-018a reemplazo al enganchar la maquina de estados.
         */
        $procesados = 0;
        Log::listen(function ($m) use (&$procesados) {
            if (($m->context['codigo'] ?? null) === 'FSM_TRANSICION') {
                $procesados++;
            }
        });

        // Meta manda el mismo webhook dos veces porque el primer 200 tardo.
        (new ProcessMessageJob($this->payload('wamid.REPETIDO')))->handle();
        (new ProcessMessageJob($this->payload('wamid.REPETIDO')))->handle();

        $this->assertSame(1, $procesados, 'El mensaje se proceso mas de una vez.');

        $this->assertSame(1, DB::table('processed_messages')
            ->where('tenant_id', $tenant->id)
            ->where('message_id', 'wamid.REPETIDO')
            ->count());
    }

    /** Mensajes distintos si se procesan los dos. */
    public function test_mensajes_distintos_se_procesan_ambos(): void
    {
        $this->tenantConIntegracion();

        (new ProcessMessageJob($this->payload('wamid.UNO')))->handle();
        (new ProcessMessageJob($this->payload('wamid.DOS')))->handle();

        $this->assertSame(2, DB::table('processed_messages')->count());
    }

    /**
     * El mismo `message_id` para dos tenants distintos no se pisa.
     *
     * No deberia pasar —el wamid es unico global— pero el unico lidera con
     * `tenant_id`, y esto verifica que la deduplicacion no filtre entre PyMEs.
     */
    public function test_el_mismo_message_id_en_dos_tenants_no_colisiona(): void
    {
        $a = $this->tenantConIntegracion('tenant-a');

        $b = Tenant::create([
            'name' => 'PyME B', 'slug' => 'tenant-b',
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
        Integration::create([
            'tenant_id' => $b->id,
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => '9999999999',
            'settings' => ['verify_token' => 'tok-b'],
            'status' => 'connected',
        ]);

        (new ProcessMessageJob($this->payload('wamid.MISMO', self::PHONE_NUMBER_ID)))->handle();
        (new ProcessMessageJob($this->payload('wamid.MISMO', '9999999999')))->handle();

        $this->assertSame(1, DB::table('processed_messages')->where('tenant_id', $a->id)->count());
        $this->assertSame(1, DB::table('processed_messages')->where('tenant_id', $b->id)->count());
    }

    /**
     * Un mensaje que falla al procesarse NO queda marcado como procesado.
     *
     * Es la mitad que importa de "marcar al terminar": si se marcara al empezar,
     * el reintento de Laravel veria "ya procesado" y lo saltearia, y el cliente
     * nunca recibiria respuesta — una perdida permanente y muda.
     */
    public function test_un_mensaje_que_falla_no_queda_marcado_como_procesado(): void
    {
        $this->tenantConIntegracion();

        $job = new class($this->payload('wamid.QUE_FALLA')) extends ProcessMessageJob
        {
            protected function entregarAlFlujo(Integration $integration, \App\Models\Conversation $conversation, array $message): void
            {
                throw new \RuntimeException('Google no responde');
            }
        };

        try {
            $job->handle();
            $this->fail('Se esperaba que la excepcion se propagara para que Laravel reintente.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Google no responde', $e->getMessage());
        }

        $this->assertSame(0, DB::table('processed_messages')->count(),
            'Un mensaje que fallo quedo marcado como procesado: el reintento lo saltearia.');
    }

    /** Tras un fallo, el reintento vuelve a procesar y esta vez marca. */
    public function test_tras_un_fallo_el_reintento_procesa_y_marca(): void
    {
        $this->tenantConIntegracion();

        $job = new class($this->payload('wamid.REINTENTO')) extends ProcessMessageJob
        {
            public static bool $debeFallar = true;

            protected function entregarAlFlujo(Integration $integration, \App\Models\Conversation $conversation, array $message): void
            {
                if (static::$debeFallar) {
                    static::$debeFallar = false;
                    throw new \RuntimeException('fallo transitorio');
                }
            }
        };

        try {
            $job->handle();
        } catch (\RuntimeException) {
            // Esperado: Laravel encola el reintento.
        }

        $this->assertSame(0, DB::table('processed_messages')->count());

        // El reintento de Laravel corre el mismo job de nuevo.
        $job->handle();

        $this->assertSame(1, DB::table('processed_messages')->count());
    }

    /** Un mensaje sin `id` no rompe el job ni se marca. */
    public function test_mensaje_sin_id_se_descarta_sin_romper(): void
    {
        $this->tenantConIntegracion();

        $payload = $this->payload('wamid.X');
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['id']);

        (new ProcessMessageJob($payload))->handle();

        $this->assertSame(0, DB::table('processed_messages')->count());
    }

    /** El job declara reintentos y backoff: no depende solo del comando del worker. */
    public function test_el_job_declara_reintentos_y_backoff(): void
    {
        $job = new ProcessMessageJob($this->payload('wamid.X'));

        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->backoff());
    }
}
