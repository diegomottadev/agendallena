<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-046 · Autenticación del panel y aislamiento por tenant.
 */
class AutenticacionPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'PyME '.$slug, 'slug' => $slug,
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
    }

    private function usuario(Tenant $t, Role $rol = Role::Owner, string $clave = 'secreto123'): User
    {
        return User::create([
            'tenant_id' => $t->id,
            'name' => $rol->etiqueta(),
            'email' => $rol->value.'@'.$t->slug.'.test',
            'password' => $clave,
            'role' => $rol,
        ]);
    }


    // ----------------------------------------------------------------- login

    public function test_el_panel_exige_login(): void
    {
        $this->get('/panel')->assertRedirect('/login');
    }

    public function test_se_puede_entrar_con_credenciales_correctas(): void
    {
        $u = $this->usuario($this->tenant('a'));

        $this->post('/login', ['email' => $u->email, 'password' => 'secreto123'])
            ->assertRedirect(route('panel'));

        $this->assertAuthenticatedAs($u);
    }

    /**
     * El mensaje de error no distingue "no existe" de "clave incorrecta".
     *
     * Si los distinguiera, el formulario sería un verificador de qué correos
     * tienen cuenta en la plataforma.
     */
    public function test_el_error_no_revela_si_el_correo_existe(): void
    {
        $u = $this->usuario($this->tenant('a'));

        $mismoMensaje = ['email' => 'Las credenciales no coinciden.'];

        $this->post('/login', ['email' => $u->email, 'password' => 'incorrecta'])
            ->assertInvalid($mismoMensaje);

        $this->post('/login', ['email' => 'nadie@ningun.test', 'password' => 'x'])
            ->assertInvalid($mismoMensaje);

        $this->assertGuest();
    }

    /** La contraseña nunca llega al log. */
    public function test_el_login_fallido_no_registra_la_contrasenia(): void
    {
        $u = $this->usuario($this->tenant('a'));

        $capturado = [];
        Log::listen(function ($m) use (&$capturado) {
            $capturado[] = $m->message.' '.json_encode($m->context);
        });

        $this->post('/login', ['email' => $u->email, 'password' => 'clave-secretisima']);

        $todo = implode("\n", $capturado);
        $this->assertNotEmpty($todo, 'No se registro el intento fallido.');
        $this->assertStringNotContainsString('clave-secretisima', $todo);
    }

    /** Fuerza bruta: se frena a los 5 intentos. */
    public function test_limita_los_intentos_de_login(): void
    {
        $u = $this->usuario($this->tenant('a'));

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $u->email, 'password' => 'mal']);
        }

        $this->post('/login', ['email' => $u->email, 'password' => 'mal'])
            ->assertInvalid(['email' => 'Demasiados intentos']);

        // Ni siquiera con la clave correcta pasa mientras dure el bloqueo.
        $this->post('/login', ['email' => $u->email, 'password' => 'secreto123']);
        $this->assertGuest();
    }

    /** La sesión se renueva al entrar: un id fijado antes del login no sirve después. */
    public function test_la_sesion_se_regenera_al_entrar(): void
    {
        $u = $this->usuario($this->tenant('a'));

        $this->get('/login');
        $idPrevio = session()->getId();

        $this->post('/login', ['email' => $u->email, 'password' => 'secreto123']);

        $this->assertNotSame($idPrevio, session()->getId());
    }

    public function test_se_puede_salir(): void
    {
        $this->actingAs($this->usuario($this->tenant('a')));

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    // ------------------------------------------------------------ aislamiento

    /**
     * El criterio central de T-046: una consulta **sin filtro explícito** queda
     * filtrada igual por el Global Scope, gracias al tenant de la sesión.
     */
    public function test_una_consulta_sin_filtro_explicito_solo_ve_el_tenant_propio(): void
    {
        $a = $this->tenant('a');
        $b = $this->tenant('b');

        $this->assertSame(2, BusinessSetting::withoutTenantScope()->count());

        $this->actingAs($this->usuario($a));

        // Se ejecuta dentro de un request para que corra el middleware.
        $this->get('/panel')->assertOk();

        $vistas = null;
        $this->app['router']->get('/_test/conteo', function () use (&$vistas) {
            $vistas = BusinessSetting::count();   // sin where de tenant

            return 'ok';
        })->middleware(['web', 'auth']);

        $this->get('/_test/conteo')->assertOk();

        $this->assertSame(1, $vistas,
            'Una consulta sin filtro explicito vio datos de mas de un tenant.');
    }

    /** El tenant no queda colgado entre peticiones. */
    public function test_el_contexto_de_tenant_se_limpia_al_terminar_el_request(): void
    {
        $this->actingAs($this->usuario($this->tenant('a')));

        $this->get('/panel')->assertOk();

        $this->assertFalse(TenantContext::has(),
            'El tenant quedo activo despues del request: el siguiente usuario lo heredaria.');
    }

    // ---------------------------------------------------------- autorizacion

    /** `staff` no puede entrar a conectar integraciones. */
    public function test_staff_recibe_403_al_intentar_configurar(): void
    {
        $t = $this->tenant('a');
        $this->actingAs($this->usuario($t, Role::Staff));

        $this->get('/api/v1/oauth/google/redirect')->assertForbidden();
    }

    /** `owner` y `admin` sí. */
    public function test_owner_y_admin_pueden_configurar(): void
    {
        foreach ([Role::Owner, Role::Admin] as $rol) {
            $t = $this->tenant('t-'.$rol->value);
            $this->actingAs($this->usuario($t, $rol));

            $this->get('/api/v1/oauth/google/redirect')->assertRedirect();
        }
    }

    /** El intento denegado queda registrado. */
    public function test_el_acceso_denegado_queda_registrado(): void
    {
        $t = $this->tenant('a');
        $this->actingAs($this->usuario($t, Role::Staff));

        Log::spy();

        $this->get('/api/v1/oauth/google/redirect')->assertForbidden();

        Log::shouldHaveReceived('warning')->once();
    }

    /** `staff` sí entra al panel: atender y supervisar es de los tres roles. */
    public function test_staff_entra_al_panel(): void
    {
        $t = $this->tenant('a');
        $this->actingAs($this->usuario($t, Role::Staff));

        $html = $this->get('/panel')->assertOk()->getContent();

        // Ve el estado, pero no el boton de conectar.
        $this->assertStringContainsString('data-puede-configurar=""', $html);
    }

    /** `owner` ve el panel con permiso de configurar. */
    public function test_owner_ve_el_panel_con_permiso_de_configurar(): void
    {
        $t = $this->tenant('a');
        $this->actingAs($this->usuario($t, Role::Owner));

        $html = $this->get('/panel')->assertOk()->getContent();

        $this->assertStringContainsString('data-puede-configurar="1"', $html);
    }
}
