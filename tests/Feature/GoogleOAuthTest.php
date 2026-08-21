<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Google\GoogleOAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-012 · Conexión OAuth 2.0 con Google Calendar.
 */
class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = '/api/v1/oauth/google/redirect';

    private const CALLBACK = '/api/v1/oauth/google/callback';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'test-client-id.apps.googleusercontent.com');
        config()->set('services.google.client_secret', 'GOCSPX-test');
        config()->set('services.google.redirect_uri', 'http://localhost:8000'.self::CALLBACK);
    }

    private function tenant(string $slug = 'piloto'): Tenant
    {
        return Tenant::create([
            'name' => 'PyME', 'slug' => $slug,
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
    }

    /** T-046 - el panel y el redirect viven detras del login. */
    private function comoUsuario(Tenant $tenant, Role $rol = Role::Owner): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $rol->etiqueta(),
            'email' => $rol->value.'@'.$tenant->slug.'.test',
            'password' => 'secreto123',
            'role' => $rol,
        ]);

        $this->actingAs($user);

        return $user;
    }

    private function respuestasDeGoogle(array $token = [], ?string $email = 'duenio@peluqueria.com'): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(array_merge([
                'access_token' => 'ya29.access-token-de-prueba',
                'refresh_token' => '1//refresh-token-de-prueba',
                'expires_in' => 3599,
            ], $token)),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => $email]),
        ]);
    }

    // ------------------------------------------------------------- redirect

    /** AC: la autorización pide `access_type=offline` para obtener refresh_token. */
    public function test_la_url_de_autorizacion_pide_acceso_offline_y_los_tres_scopes(): void
    {
        $this->comoUsuario($this->tenant());

        $destino = $this->get(self::REDIRECT)->assertRedirect()->headers->get('Location');

        parse_str((string) parse_url($destino, PHP_URL_QUERY), $q);

        $this->assertSame('offline', $q['access_type'] ?? null,
            'Sin access_type=offline Google no entrega refresh_token.');
        // Sin `prompt=consent`, una reautorizacion no devuelve refresh_token.
        $this->assertSame('consent', $q['prompt'] ?? null);
        $this->assertStringContainsString('calendar.events', $q['scope']);
        $this->assertStringContainsString('calendar.readonly', $q['scope']);
        // El scope de Sheets va en el MISMO consentimiento: si se pide despues,
        // el cliente autoriza dos veces.
        $this->assertStringContainsString('spreadsheets', $q['scope']);
        $this->assertNotEmpty($q['state'] ?? null);
    }

    /** El `state` transporta el tenant y está firmado. */
    public function test_el_state_transporta_el_tenant_y_esta_firmado(): void
    {
        $tenant = $this->tenant();
        $this->comoUsuario($tenant);

        $destino = $this->get(self::REDIRECT)->headers->get('Location');
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $q);

        $this->assertSame($tenant->id, GoogleOAuthState::verificar($q['state']));

        // Un byte cambiado invalida la firma.
        $adulterado = substr($q['state'], 0, -1).'X';
        $this->assertNull(GoogleOAuthState::verificar($adulterado));
    }

    // ------------------------------------------------------------- callback

    /** AC-04.1 · Autorizar deja la integración conectada con el nombre de la cuenta. */
    public function test_autorizar_deja_la_integracion_conectada_con_la_cuenta(): void
    {
        $tenant = $this->tenant();
        $this->respuestasDeGoogle();

        $this->get(self::CALLBACK.'?'.http_build_query([
            'code' => 'codigo-de-google',
            'state' => GoogleOAuthState::emitir($tenant->id),
        ]))->assertRedirect('/panel?google=conectado');

        $i = Integration::where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)->first();

        $this->assertNotNull($i);
        $this->assertSame('connected', $i->status);
        $this->assertSame('duenio@peluqueria.com', $i->account_identifier);
        $this->assertSame('ya29.access-token-de-prueba', $i->access_token);
        $this->assertSame('1//refresh-token-de-prueba', $i->refresh_token);
        $this->assertNotNull($i->expires_at);
    }

    /** AC-04.2 · Los tokens no son legibles en texto plano en la base. */
    public function test_los_tokens_estan_encriptados_en_reposo(): void
    {
        $tenant = $this->tenant();
        $this->respuestasDeGoogle();

        $this->get(self::CALLBACK.'?'.http_build_query([
            'code' => 'codigo', 'state' => GoogleOAuthState::emitir($tenant->id),
        ]));

        $crudo = DB::table('integrations')->where('provider', 'google_calendar')->first();

        $this->assertStringNotContainsString('ya29.access-token-de-prueba', $crudo->access_token);
        $this->assertStringNotContainsString('1//refresh-token-de-prueba', (string) $crudo->refresh_token);
    }

    /** AC-04.3 · Cancelar el consentimiento no guarda nada y explica cómo reintentar. */
    public function test_cancelar_el_consentimiento_no_guarda_nada(): void
    {
        $this->tenant();

        $this->get(self::CALLBACK.'?error=access_denied')
            ->assertRedirect('/panel?google=cancelado');

        $this->assertSame(0, Integration::where('provider', 'google_calendar')->count());
    }

    /** AC: un callback sin `state` válido se rechaza. */
    public function test_rechaza_el_callback_sin_state_valido(): void
    {
        $this->tenant();
        Http::fake();

        foreach (['', 'inventado', 'a.b'] as $state) {
            $this->get(self::CALLBACK.'?'.http_build_query(['code' => 'x', 'state' => $state]))
                ->assertRedirect('/panel?google=error&motivo=state_invalido');
        }

        $this->assertSame(0, Integration::where('provider', 'google_calendar')->count());
        // Un state invalido no merece ni una llamada a Google.
        Http::assertNothingSent();
    }

    /** El `state` de un tenant no sirve para otro: se guarda donde dice el state. */
    public function test_el_state_determina_el_tenant_y_no_el_parametro(): void
    {
        $a = $this->tenant('tenant-a');
        $b = $this->tenant('tenant-b');
        $this->respuestasDeGoogle();

        $this->get(self::CALLBACK.'?'.http_build_query([
            'code' => 'x', 'state' => GoogleOAuthState::emitir($b->id), 'tenant' => $a->slug,
        ]));

        $i = Integration::where('provider', 'google_calendar')->first();
        $this->assertSame($b->id, $i->tenant_id);
    }

    /** Un `state` vencido se rechaza. */
    public function test_un_state_vencido_se_rechaza(): void
    {
        $tenant = $this->tenant();
        $state = GoogleOAuthState::emitir($tenant->id);

        $this->travel(16)->minutes();

        $this->assertNull(GoogleOAuthState::verificar($state));
    }

    /** Si Google rechaza el canje, no queda una integración a medias. */
    public function test_si_google_rechaza_el_canje_no_guarda_nada(): void
    {
        $tenant = $this->tenant();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->get(self::CALLBACK.'?'.http_build_query([
            'code' => 'vencido', 'state' => GoogleOAuthState::emitir($tenant->id),
        ]))->assertRedirect('/panel?google=error&motivo=canje_fallido');

        $this->assertSame(0, Integration::where('provider', 'google_calendar')->count());
    }

    /**
     * Reconectar sin `refresh_token` nuevo **conserva** el anterior.
     *
     * Google no reenvía el refresh en una reautorización. Pisarlo con `null`
     * dejaría la integración viva una hora y muerta después, sin recuperarse.
     */
    public function test_reconectar_sin_refresh_token_conserva_el_anterior(): void
    {
        $tenant = $this->tenant();

        $this->respuestasDeGoogle();
        $this->get(self::CALLBACK.'?'.http_build_query([
            'code' => 'x', 'state' => GoogleOAuthState::emitir($tenant->id),
        ]));

        // Segunda vuelta: Google no manda refresh_token.
        $this->respuestasDeGoogle(['refresh_token' => null]);
        $this->get(self::CALLBACK.'?'.http_build_query([
            'code' => 'y', 'state' => GoogleOAuthState::emitir($tenant->id),
        ]));

        $i = Integration::where('provider', 'google_calendar')->first();
        $this->assertSame('1//refresh-token-de-prueba', $i->refresh_token,
            'Se perdio el refresh_token al reconectar.');
        $this->assertSame(1, Integration::where('provider', 'google_calendar')->count());
    }

    // ---------------------------------------------------------------- panel

    /** AC-04.1 · El panel muestra el indicador y la cuenta vinculada. */
    public function test_el_panel_muestra_la_cuenta_conectada(): void
    {
        $tenant = $this->tenant();
        $this->respuestasDeGoogle();
        $this->get(self::CALLBACK.'?'.http_build_query([
            'code' => 'x', 'state' => GoogleOAuthState::emitir($tenant->id),
        ]));

        $this->comoUsuario($tenant);
        $html = $this->get('/panel')->assertOk()->getContent();

        $this->assertStringContainsString('duenio@peluqueria.com', $html);
        $this->assertStringContainsString('connected', $html);
    }

    /** El panel nunca serializa un token. */
    public function test_el_panel_no_expone_los_tokens(): void
    {
        $tenant = $this->tenant();
        $this->respuestasDeGoogle();
        $this->get(self::CALLBACK.'?'.http_build_query([
            'code' => 'x', 'state' => GoogleOAuthState::emitir($tenant->id),
        ]));

        $this->comoUsuario($tenant);
        $html = $this->get('/panel')->getContent();

        $this->assertStringNotContainsString('ya29.access-token-de-prueba', $html);
        $this->assertStringNotContainsString('1//refresh-token-de-prueba', $html);
    }
}
