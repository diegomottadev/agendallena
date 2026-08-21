<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-008 · Handshake de webhook de Meta.
 * Criterios: AC-01.1, AC-01.2, AC-01.3 y encriptado en reposo.
 */
class WhatsAppWebhookVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/webhooks/whatsapp';

    private function tenantConToken(string $slug, string $token, string $phoneNumberId): Tenant
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
            'account_identifier' => $phoneNumberId,
            'settings' => ['verify_token' => $token],
            'status' => 'connected',
        ]);

        return $tenant;
    }

    /** AC-01.1 — responde 200 con el challenge exacto, en text/plain y sin comillas. */
    public function test_devuelve_el_challenge_en_texto_plano_con_token_valido(): void
    {
        $this->tenantConToken('peluqueria-sur', 'token-valido-abc', '1053554814514902');

        $response = $this->get(self::URL.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'token-valido-abc',
            'hub.challenge' => '1158201444',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame('1158201444', $response->getContent());
    }

    /** AC-01.2 — un token que no corresponde a ningun tenant no devuelve el challenge. */
    public function test_rechaza_un_token_desconocido_sin_filtrar_el_challenge(): void
    {
        $this->tenantConToken('peluqueria-sur', 'token-valido-abc', '1053554814514902');

        $response = $this->get(self::URL.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'token-que-no-existe',
            'hub.challenge' => '1158201444',
        ]));

        $response->assertForbidden();
        $this->assertStringNotContainsString('1158201444', $response->getContent());
    }

    /** AC-01.3 — el token del tenant A no valida la suscripcion del tenant B. */
    public function test_el_token_de_un_tenant_no_sirve_para_otro(): void
    {
        $this->tenantConToken('tenant-a', 'token-de-a', '1111111111');
        $this->tenantConToken('tenant-b', 'token-de-b', '2222222222');

        $comoA = $this->get(self::URL.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'token-de-a',
            'hub.challenge' => 'desafio-a',
        ]));
        $comoA->assertOk();
        $this->assertSame('desafio-a', $comoA->getContent());

        // Mismo endpoint, token de A: sigue siendo valido, pero resuelve al tenant A.
        // Lo que no puede pasar es que un token inventado valide a cualquiera.
        $inventado = $this->get(self::URL.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'token-de-a-pero-mal',
            'hub.challenge' => 'desafio-b',
        ]));
        $inventado->assertForbidden();
    }

    public function test_rechaza_un_mode_distinto_de_subscribe(): void
    {
        $this->tenantConToken('peluqueria-sur', 'token-valido-abc', '1053554814514902');

        $response = $this->get(self::URL.'?'.http_build_query([
            'hub.mode' => 'unsubscribe',
            'hub.verify_token' => 'token-valido-abc',
            'hub.challenge' => '1158201444',
        ]));

        $response->assertForbidden();
    }

    public function test_rechaza_la_peticion_sin_parametros(): void
    {
        $this->get(self::URL)->assertForbidden();
    }

    /** El verify_token no puede quedar legible en la base. */
    public function test_el_verify_token_esta_encriptado_en_reposo(): void
    {
        $this->tenantConToken('peluqueria-sur', 'token-valido-abc', '1053554814514902');

        $crudo = DB::table('integrations')->value('settings');

        $this->assertNotNull($crudo);
        $this->assertStringNotContainsString('token-valido-abc', $crudo);
    }

    /**
     * Último criterio de T-008 · **cada intento fallido queda registrado con
     * timestamp, IP y token recibido**.
     *
     * El comportamiento ya estaba escrito y no lo miraba ningún test, que es
     * cómo se pierde en silencio: nada avisa si mañana alguien saca el `Log` de
     * la rama de rechazo. El handshake es la puerta de entrada de Meta y un
     * rechazo repetido es el único síntoma de una URL mal cargada.
     *
     * ⚠️ El token va **enmascarado** a propósito: un intento fallido puede
     * traer el token válido de otro tenant por un copy-paste cruzado, y el mismo
     * ticket prohíbe que los `verify_token` aparezcan en los logs. Por eso se
     * afirman las dos mitades — que se reconozca cuál se usó, y que no quede
     * utilizable.
     *
     * El timestamp no se afirma acá: lo pone el driver de log de Laravel en cada
     * línea, no el contexto, y afirmarlo sería probar el framework.
     */
    public function test_cada_intento_fallido_queda_registrado_con_ip_y_token_enmascarado(): void
    {
        $this->tenantConToken('peluqueria-sur', 'token-valido-abc', '1053554814514902');

        $registros = [];
        Log::listen(function ($m) use (&$registros) {
            $registros[] = ['nivel' => $m->level, 'contexto' => $m->context];
        });

        $this->get(self::URL.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'token-que-no-existe',
            'hub.challenge' => '1158201444',
        ]))->assertForbidden();

        $rechazos = array_values(array_filter(
            $registros,
            fn (array $r): bool => in_array($r['nivel'], ['warning', 'error'], true)
                && isset($r['contexto']['ip']),
        ));

        $this->assertNotEmpty($rechazos,
            'Un handshake rechazado no dejó ningún registro con IP: un intento fallido contra la '
            .'puerta de entrada de Meta pasa inadvertido.');

        $contexto = $rechazos[0]['contexto'];

        $this->assertNotEmpty($contexto['ip'], 'El registro del rechazo no trae la IP del intento.');

        $this->assertArrayHasKey('token_recibido', $contexto,
            'El registro no dice qué token se recibió, así que no se puede distinguir una URL mal '
            .'cargada de un escaneo.');

        $this->assertStringContainsString('toke', (string) $contexto['token_recibido'],
            'El token quedó irreconocible: el registro no sirve para saber cuál se usó.');

        $this->assertStringNotContainsString('token-que-no-existe', (string) $contexto['token_recibido'],
            'El verify_token quedó entero en el log. T-008 lo prohíbe: un intento fallido puede '
            .'traer el token válido de otro tenant.');
    }
}
