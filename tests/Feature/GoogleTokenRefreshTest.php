<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Google\GoogleConnection;
use App\Services\Google\GoogleIntegracionVencida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-013 · Renovación automática de tokens y marca de vencimiento.
 */
class GoogleTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'test.apps.googleusercontent.com');
        config()->set('services.google.client_secret', 'GOCSPX-test');
    }

    private function integracion(array $atributos = []): Integration
    {
        $tenant = Tenant::create([
            'name' => 'PyME', 'slug' => 'piloto-'.uniqid(),
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        return Integration::create(array_merge([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio@peluqueria.com',
            'access_token' => 'token-viejo',
            'refresh_token' => 'refresh-original',
            'expires_at' => now()->subMinutes(5),   // vencido
            'status' => 'connected',
        ], $atributos));
    }

    private function conexion(): GoogleConnection
    {
        return app(GoogleConnection::class);
    }

    private function googleRenueva(array $extra = []): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(array_merge([
            'access_token' => 'token-nuevo',
            'expires_in' => 3599,
        ], $extra))]);
    }

    /** AC-04.4 · El token vencido se renueva solo y la operación se completa. */
    public function test_renueva_el_token_vencido_sin_intervencion_y_completa_la_operacion(): void
    {
        $i = $this->integracion();
        $this->googleRenueva();

        $tokenUsado = null;
        $resultado = $this->conexion()->ejecutar($i, function (string $token) use (&$tokenUsado) {
            $tokenUsado = $token;

            return 'listado de eventos';
        });

        $this->assertSame('listado de eventos', $resultado);
        $this->assertSame('token-nuevo', $tokenUsado, 'La operacion corrio con el token viejo.');
        $this->assertSame('token-nuevo', $i->fresh()->access_token);
        $this->assertTrue($i->fresh()->expires_at->isFuture());
    }

    /** Un token vigente no gasta una renovación. */
    public function test_no_renueva_si_el_token_sigue_vigente(): void
    {
        $i = $this->integracion(['expires_at' => now()->addMinutes(30)]);
        Http::fake();

        $this->conexion()->ejecutar($i, fn (string $t) => $t);

        Http::assertNothingSent();
    }

    /** Renueva con margen: un token que vence en segundos se trata como vencido. */
    public function test_renueva_con_margen_antes_del_vencimiento_real(): void
    {
        $i = $this->integracion(['expires_at' => now()->addSeconds(30)]);
        $this->googleRenueva();

        $this->conexion()->ejecutar($i, fn (string $t) => $t);

        $this->assertSame('token-nuevo', $i->fresh()->access_token);
    }

    /** AC · Tras un 401, la operación se reintenta EXACTAMENTE una vez. */
    public function test_reintenta_la_operacion_exactamente_una_vez_tras_un_401(): void
    {
        $i = $this->integracion(['expires_at' => now()->addMinutes(30)]);
        $this->googleRenueva();

        $llamadas = 0;
        $this->conexion()->ejecutar($i, function (string $token) use (&$llamadas) {
            $llamadas++;

            // Siempre 401: si hubiera bucle, esto no terminaria nunca.
            return new class
            {
                public function status(): int
                {
                    return 401;
                }
            };
        });

        $this->assertSame(2, $llamadas, 'La operacion no se ejecuto exactamente dos veces (original + un reintento).');
    }

    /** AC · Ante `invalid_grant` la integración queda `expired`. */
    public function test_ante_invalid_grant_marca_la_integracion_como_vencida(): void
    {
        $i = $this->integracion();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            $this->conexion()->ejecutar($i, fn (string $t) => 'no deberia llegar');
            $this->fail('Se esperaba GoogleIntegracionVencida.');
        } catch (GoogleIntegracionVencida $e) {
            $this->assertSame($i->id, $e->integration->id);
        }

        $this->assertSame('expired', $i->fresh()->status);
    }

    /** AC · Con la integración vencida se deja de intentar: no se llama a Google. */
    public function test_una_integracion_vencida_no_vuelve_a_intentar(): void
    {
        $i = $this->integracion(['status' => 'expired']);
        Http::fake();

        $this->expectException(GoogleIntegracionVencida::class);

        try {
            $this->conexion()->ejecutar($i, fn (string $t) => 'x');
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * Un fallo transitorio **no** marca la integración como vencida.
     *
     * Es la mitad que importa: si un 500 de Google de treinta segundos dejara la
     * integración en `expired`, el cliente queda desconectado hasta que alguien
     * lo note y reconecte a mano.
     */
    public function test_un_fallo_transitorio_no_marca_la_integracion_como_vencida(): void
    {
        $i = $this->integracion();
        Http::fake(['oauth2.googleapis.com/token' => Http::response('Service Unavailable', 503)]);

        try {
            $this->conexion()->ejecutar($i, fn (string $t) => 'x');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(GoogleIntegracionVencida::class, $e);
        }

        $this->assertSame('connected', $i->fresh()->status,
            'Un 503 pasajero dejo la integracion marcada como vencida.');
    }

    /** AC · Si Google rota el refresh_token, el nuevo reemplaza al anterior. */
    public function test_si_google_rota_el_refresh_token_reemplaza_al_anterior(): void
    {
        $i = $this->integracion();
        $this->googleRenueva(['refresh_token' => 'refresh-rotado']);

        $this->conexion()->ejecutar($i, fn (string $t) => 'ok');

        $this->assertSame('refresh-rotado', $i->fresh()->refresh_token);
    }

    /** Si Google NO lo rota —el caso habitual—, se conserva el anterior. */
    public function test_si_google_no_rota_el_refresh_token_conserva_el_anterior(): void
    {
        $i = $this->integracion();
        $this->googleRenueva();   // sin refresh_token en la respuesta

        $this->conexion()->ejecutar($i, fn (string $t) => 'ok');

        $this->assertSame('refresh-original', $i->fresh()->refresh_token,
            'Se perdio el refresh_token: la proxima renovacion seria imposible.');
    }

    /** El refresh_token renovado sigue encriptado en reposo. */
    public function test_el_refresh_token_rotado_queda_encriptado(): void
    {
        $i = $this->integracion();
        $this->googleRenueva(['refresh_token' => 'refresh-rotado']);

        $this->conexion()->ejecutar($i, fn (string $t) => 'ok');

        $crudo = DB::table('integrations')->where('id', $i->id)->first();

        $this->assertStringNotContainsString('refresh-rotado', (string) $crudo->refresh_token);
        $this->assertStringNotContainsString('token-nuevo', (string) $crudo->access_token);
    }

    /** Sin refresh_token no hay nada que renovar: queda vencida. */
    public function test_sin_refresh_token_queda_vencida(): void
    {
        $i = $this->integracion(['refresh_token' => null]);
        Http::fake();

        $this->expectException(GoogleIntegracionVencida::class);

        try {
            $this->conexion()->ejecutar($i, fn (string $t) => 'x');
        } finally {
            $this->assertSame('expired', $i->fresh()->status);
        }
    }
}
