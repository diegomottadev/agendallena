<?php

namespace Tests\Feature;

use App\Jobs\ProcessMessageJob;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-048 · Historial de mensajes.
 */
class MessageHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_NUMBER_ID = '1053554814514902';

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function tenantConIntegracion(string $slug = 'piloto'): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'PyME '.$slug, 'slug' => $slug,
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => self::PHONE_NUMBER_ID,
            'settings' => ['verify_token' => 'tok'],
            'status' => 'connected',
        ]);

        return $tenant;
    }

    /** @return array<string,mixed> */
    private function payload(array $mensaje): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => self::PHONE_NUMBER_ID],
                'messages' => [$mensaje],
            ],
        ]]]]];
    }

    /** @return array<string,mixed> */
    private function mensajeTexto(string $id, string $texto = 'Hola', string $de = '5491133334444'): array
    {
        return ['from' => $de, 'id' => $id, 'type' => 'text', 'text' => ['body' => $texto]];
    }

    /** Cada entrante queda persistido con dirección e identificador de Meta. */
    public function test_persiste_el_mensaje_entrante_con_direccion_e_id_de_meta(): void
    {
        $tenant = $this->tenantConIntegracion();

        (new ProcessMessageJob($this->payload($this->mensajeTexto('wamid.UNO', 'Quiero un turno'))))->handle();

        TenantContext::runAs($tenant->id, function () {
            $m = Message::first();

            $this->assertNotNull($m, 'No se persistio el mensaje entrante.');
            $this->assertSame(Message::ENTRANTE, $m->direction);
            $this->assertSame('wamid.UNO', $m->whatsapp_message_id);
            $this->assertSame('Quiero un turno', $m->content);
            $this->assertSame('text', $m->type);
            // El payload integro queda para poder reprocesar sin pedirle nada a Meta.
            $this->assertSame('5491133334444', $m->payload['from']);
        });
    }

    /** El mensaje cuelga de una conversación resuelta por (tenant, teléfono). */
    public function test_crea_la_conversacion_y_le_cuelga_el_mensaje(): void
    {
        $tenant = $this->tenantConIntegracion();

        (new ProcessMessageJob($this->payload($this->mensajeTexto('wamid.UNO'))))->handle();

        TenantContext::runAs($tenant->id, function () {
            $c = Conversation::first();

            $this->assertNotNull($c);
            $this->assertSame('5491133334444', $c->user_phone);
            $this->assertCount(1, $c->messages);

            /*
             * La conversacion nace en IDLE pero **no se queda ahi**: T-018a
             * aplica `MensajeInicial` sobre el primer mensaje, asi que para
             * cuando el job termina ya esta en GATHERING_PARAMS.
             */
            $this->assertSame('GATHERING_PARAMS', $c->current_state);
        });
    }

    /** Dos mensajes del mismo teléfono van a la misma conversación. */
    public function test_dos_mensajes_del_mismo_telefono_comparten_conversacion(): void
    {
        $tenant = $this->tenantConIntegracion();

        (new ProcessMessageJob($this->payload($this->mensajeTexto('wamid.UNO', 'Hola'))))->handle();
        (new ProcessMessageJob($this->payload($this->mensajeTexto('wamid.DOS', 'Para el martes'))))->handle();

        TenantContext::runAs($tenant->id, function () {
            $this->assertSame(1, Conversation::count());
            $this->assertSame(2, Message::count());
        });
    }

    /**
     * AC-08.1 · Un mensaje recibido cuando el bot NO responde queda registrado.
     *
     * Es el criterio que fija el orden de las operaciones: si se persistiera
     * después de entregar al flujo, todo camino que decide no contestar
     * —pausa humana, estado sin transición— perdería el mensaje.
     */
    public function test_se_registra_aunque_el_bot_no_responda(): void
    {
        $tenant = $this->tenantConIntegracion();

        // Simula la pausa humana de T-025: el flujo decide no hacer nada.
        $job = new class($this->payload($this->mensajeTexto('wamid.EN_PAUSA', 'Hola?'))) extends ProcessMessageJob
        {
            protected function entregarAlFlujo(Integration $integration, \App\Models\Conversation $conversation, array $message): void
            {
                // El bot está pausado: no contesta.
            }
        };

        $job->handle();

        TenantContext::runAs($tenant->id, function () {
            $this->assertSame(1, Message::count(),
                'El mensaje se perdio porque el bot no respondio.');
            $this->assertSame('Hola?', Message::first()->content);
        });
    }

    /** Una reentrega de Meta no duplica la fila del historial. */
    public function test_una_reentrega_no_duplica_el_mensaje(): void
    {
        $tenant = $this->tenantConIntegracion();
        $payload = $this->payload($this->mensajeTexto('wamid.REPETIDO'));

        (new ProcessMessageJob($payload))->handle();
        (new ProcessMessageJob($payload))->handle();

        TenantContext::runAs($tenant->id, fn () => $this->assertSame(1, Message::count()));
    }

    /** Un interactivo guarda el título del botón como contenido legible. */
    public function test_un_boton_guarda_su_titulo_como_contenido(): void
    {
        $tenant = $this->tenantConIntegracion();

        (new ProcessMessageJob($this->payload([
            'from' => '5491133334444', 'id' => 'wamid.BOTON', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'confirmar_v1', 'title' => 'Confirmar']],
        ])))->handle();

        TenantContext::runAs($tenant->id, function () {
            $m = Message::first();
            $this->assertSame('Confirmar', $m->content, 'El historial quedaria sin contenido legible.');
            $this->assertSame('interactive', $m->type);
        });
    }

    /** RNF-01: el historial de un tenant no se ve desde otro. */
    public function test_el_historial_esta_aislado_por_tenant(): void
    {
        $a = $this->tenantConIntegracion('tenant-a');

        $b = Tenant::create([
            'name' => 'B', 'slug' => 'tenant-b',
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
        Integration::create([
            'tenant_id' => $b->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => '9999999999', 'settings' => ['verify_token' => 'x'], 'status' => 'connected',
        ]);

        (new ProcessMessageJob($this->payload($this->mensajeTexto('wamid.DE_A'))))->handle();

        $payloadB = ['entry' => [['changes' => [[
            'value' => [
                'metadata' => ['phone_number_id' => '9999999999'],
                'messages' => [$this->mensajeTexto('wamid.DE_B')],
            ],
        ]]]]];
        (new ProcessMessageJob($payloadB))->handle();

        $this->assertSame(2, DB::table('messages')->count());

        TenantContext::runAs($a->id, function () {
            $this->assertSame(1, Message::count());
            $this->assertSame('wamid.DE_A', Message::first()->whatsapp_message_id);
        });
        TenantContext::runAs($b->id, function () {
            $this->assertSame('wamid.DE_B', Message::first()->whatsapp_message_id);
        });
    }

    /** Borrar la conversación se lleva su historial. */
    public function test_borrar_la_conversacion_borra_sus_mensajes(): void
    {
        $tenant = $this->tenantConIntegracion();

        (new ProcessMessageJob($this->payload($this->mensajeTexto('wamid.UNO'))))->handle();

        TenantContext::runAs($tenant->id, function () {
            Conversation::first()->delete();
            $this->assertSame(0, Message::count());
        });
    }
}
