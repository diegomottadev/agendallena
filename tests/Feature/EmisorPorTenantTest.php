<?php

namespace Tests\Feature;

use App\Conversacion\Estado;
use App\Jobs\ProcessMessageJob;
use App\Meta\MetaAdapter;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cada PyME envía sus mensajes de WhatsApp **desde su propio número**.
 *
 * El emisor de un tenant es `integrations.account_identifier` de su fila
 * `meta_whatsapp` — la misma columna con la que `ProcessMessageJob` mapea un
 * webhook entrante a su tenant. Si el número de salida sale de otro lado, un
 * cliente de la peluquería recibe la respuesta desde el número del consultorio.
 *
 * **Por qué este test no existía:** los ~14 tests que ejercitan el envío montan
 * un solo número y lo escriben además en `config('services.meta.phone_number_id')`.
 * Con un único número, "el número del tenant" y "el número global" son
 * indistinguibles y cualquiera de los dos hace pasar el test.
 *
 * Acá el escenario está montado al revés a propósito: **el número global de
 * `config` es el del tenant A**, y todos los envíos que se ejercitan son del
 * tenant B. Si el código lee el global, la petición sale con el número de A.
 */
class EmisorPorTenantTest extends TestCase
{
    use RefreshDatabase;

    /** El número del tenant A. Es también el que queda en `config`, a propósito. */
    private const PHONE_ID_A = '1053554814514902';

    /** El número del tenant B. No es substring del de A: la aserción distingue. */
    private const PHONE_ID_B = '7742019983365574';

    private const TELEFONO_CLIENTE_A = '5493764278402';

    private const TELEFONO_CLIENTE_B = '5491133445566';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Integration $metaA;

    private Integration $metaB;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * El global apunta al tenant A. Es el corazón del test: cualquier envío
         * de B que salga con este número está leyendo la config y no la
         * integración.
         */
        config()->set('services.meta.phone_number_id', self::PHONE_ID_A);

        [$this->tenantA, $this->metaA] = $this->pyme('Peluquería Sur', 'peluqueria', self::PHONE_ID_A, 'token-de-a');
        [$this->tenantB, $this->metaB] = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_ID_B, 'token-de-b');
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------- utilidades

    /** @return array{0:Tenant,1:Integration} */
    private function pyme(string $nombre, string $slug, string $phoneNumberId, string $token): array
    {
        $tenant = Tenant::create([
            'name' => $nombre, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $meta = Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId, 'access_token' => $token,
            'settings' => ['verify_token' => 'tok-'.$slug], 'status' => 'connected',
        ]);

        return [$tenant, $meta];
    }

    private function conversacionDe(Tenant $tenant, string $telefono): Conversation
    {
        return TenantContext::runAs($tenant->id, fn () => Conversation::create([
            'user_phone' => $telefono,
            'current_state' => Estado::Idle->value,
            'last_interaction_at' => now(),
        ]));
    }

    /**
     * Las URLs que efectivamente se pidieron a Meta.
     *
     * @return array<int,string>
     */
    private function urlsAMeta(): array
    {
        $urls = [];
        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com')) {
                $urls[] = $req->url();
            }
        }

        return $urls;
    }

    /**
     * ¿Alguna petición a Meta llevó este `phone_number_id` como emisor?
     *
     * Se compara el segmento entero de la ruta y no un `str_contains` suelto:
     * un número que aparezca en el query string o dentro de otro no cuenta.
     */
    private function seEnvioDesde(string $phoneNumberId): bool
    {
        foreach ($this->urlsAMeta() as $url) {
            if (str_contains($url, '/'.$phoneNumberId.'/messages')) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Alguna petición a Meta fue autorizada con esta credencial?
     *
     * Devuelve un booleano y **nunca la credencial**: así ninguna aserción que
     * falle la imprime en la salida del test.
     */
    private function seAutorizoCon(string $credencial): bool
    {
        foreach (Http::recorded() as [$req, $res]) {
            if (! str_contains($req->url(), 'graph.facebook.com')) {
                continue;
            }
            if ($req->hasHeader('Authorization', 'Bearer '.$credencial)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function payloadDeTexto(string $phoneNumberId, string $telefono, string $texto): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'metadata' => ['phone_number_id' => $phoneNumberId],
                'messages' => [[
                    'from' => $telefono, 'id' => 'wamid.'.uniqid('', true),
                    'type' => 'text', 'text' => ['body' => $texto],
                ]],
            ],
        ]]]]];
    }

    // --------------------------------------------------- el emisor es del tenant

    /**
     * El criterio, en su forma más directa: el mensaje de un cliente del tenant
     * B sale por el número del tenant B.
     */
    public function test_el_mensaje_de_un_tenant_sale_por_el_numero_de_ese_tenant(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $conversacion = $this->conversacionDe($this->tenantB, self::TELEFONO_CLIENTE_B);

        // El `tenant_id` es UUID: se compara como string. Un `(int)` da 1 para
        // todos y esta precondición pasaría con la conversación del tenant equivocado.
        $this->assertSame((string) $this->tenantB->id, (string) $conversacion->tenant_id,
            'Precondición: la conversación tiene que ser del tenant B.');

        TenantContext::runAs($this->tenantB->id, function () use ($conversacion) {
            (new MetaAdapter($this->metaB))->enviarTexto($conversacion, 'Hola, ¿en qué te ayudo?');
        });

        $this->assertTrue($this->seEnvioDesde(self::PHONE_ID_B),
            'La respuesta al cliente del tenant B no salió por el número del tenant B. URLs: '
            .implode(', ', $this->urlsAMeta()));

        $this->assertFalse($this->seEnvioDesde(self::PHONE_ID_A),
            'La respuesta a un cliente del tenant B salió por el número del tenant A: '
            .'el emisor se está leyendo de la config global y no de la integración del tenant.');
    }

    /**
     * La otra mitad de la credencial: el `Bearer` también es del tenant B.
     *
     * Una credencial del tenant A contra el número del tenant B la rechaza Meta,
     * así que las dos tienen que venir de la misma fila de `integrations`.
     */
    public function test_el_mensaje_de_un_tenant_se_autoriza_con_la_credencial_de_ese_tenant(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $conversacion = $this->conversacionDe($this->tenantB, self::TELEFONO_CLIENTE_B);

        TenantContext::runAs($this->tenantB->id, function () use ($conversacion) {
            (new MetaAdapter($this->metaB))->enviarTexto($conversacion, 'Hola');
        });

        $this->assertTrue($this->seAutorizoCon('token-de-b'),
            'El envío del tenant B no se autorizó con la credencial del tenant B.');

        $this->assertFalse($this->seAutorizoCon('token-de-a'),
            'El envío del tenant B se autorizó con la credencial del tenant A.');
    }

    /**
     * Dos tenants, dos envíos, dos números distintos.
     *
     * Es la afirmación literal del criterio —*cada* PyME desde *su* número— y la
     * que no puede cumplirse con un emisor global: con un solo número, las dos
     * URLs saldrían idénticas.
     *
     * Un solo `Http::fake()` para las dos respuestas: un segundo `fake()` no
     * reemplaza al primero, lo acumula, y el doble nuevo nunca se usa.
     */
    public function test_dos_tenants_envian_cada_uno_desde_su_propio_numero(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $convA = $this->conversacionDe($this->tenantA, self::TELEFONO_CLIENTE_A);
        $convB = $this->conversacionDe($this->tenantB, self::TELEFONO_CLIENTE_B);

        TenantContext::runAs($this->tenantA->id, fn () => (new MetaAdapter($this->metaA))->enviarTexto($convA, 'Hola A'));
        TenantContext::runAs($this->tenantB->id, fn () => (new MetaAdapter($this->metaB))->enviarTexto($convB, 'Hola B'));

        $urls = $this->urlsAMeta();
        $this->assertCount(2, $urls, 'Precondición: tienen que haber salido dos mensajes.');

        $this->assertStringContainsString('/'.self::PHONE_ID_A.'/messages', $urls[0],
            'El mensaje del tenant A no salió por el número del tenant A.');

        $this->assertStringContainsString('/'.self::PHONE_ID_B.'/messages', $urls[1],
            'El mensaje del tenant B no salió por el número del tenant B: los dos tenants '
            .'están emitiendo desde el mismo número.');

        $this->assertNotSame($urls[0], $urls[1],
            'Los dos tenants enviaron a la misma URL: hay un único emisor global.');
    }

    /**
     * El flujo real: entra un webhook al número del tenant B y la respuesta
     * vuelve por ese mismo número.
     *
     * Recorre `ProcessMessageJob`, que es quien resuelve el tenant a partir del
     * `phone_number_id` del webhook. Es el camino que ejercita el producto: si
     * el emisor de salida no coincide con el de entrada, el cliente recibe la
     * respuesta desde un número al que nunca escribió.
     */
    public function test_el_flujo_completo_responde_por_el_numero_que_recibio_el_mensaje(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]])]);

        $this->conversacionDe($this->tenantB, self::TELEFONO_CLIENTE_B);

        (new ProcessMessageJob(
            $this->payloadDeTexto(self::PHONE_ID_B, self::TELEFONO_CLIENTE_B, 'Hola')
        ))->handle();

        $this->assertNotEmpty($this->urlsAMeta(),
            'Precondición: el flujo tiene que haberle respondido algo al cliente.');

        $this->assertTrue($this->seEnvioDesde(self::PHONE_ID_B),
            'El webhook entró por el número del tenant B y la respuesta no salió por ese número. URLs: '
            .implode(', ', $this->urlsAMeta()));

        $this->assertFalse($this->seEnvioDesde(self::PHONE_ID_A),
            'El webhook entró por el número del tenant B y la respuesta salió por el número del tenant A.');
    }
}
