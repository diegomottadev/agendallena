<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-017 · Configuración de los mensajes del bot.
 */
class ConfiguracionMensajesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/panel/configuracion/mensajes';

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function entrarComo(Role $rol = Role::Owner, string $slug = 'piloto'): User
    {
        $tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => $slug,
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $rol->etiqueta(),
            'email' => $rol->value.'@'.$slug.'.test',
            'password' => 'secreto123',
            'role' => $rol,
        ]);

        $this->actingAs($user);

        return $user;
    }

    private function configDe(User $user): BusinessSetting
    {
        return BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $user->tenant_id)->firstOrFail();
    }

    /** AC-15.5 · Editados los textos, el bot usa los nuevos. */
    public function test_guarda_los_tres_textos(): void
    {
        $user = $this->entrarComo();

        $this->put(self::URL, [
            'welcome_message' => 'Hola, soy el bot de Peluquería Sur.',
            'no_availability_message' => 'No me queda lugar ese día.',
            'fallback_message' => 'Se nos cayó el sistema, ya te atiende alguien.',
        ])->assertRedirect(self::URL);

        $config = $this->configDe($user);

        $this->assertSame('Hola, soy el bot de Peluquería Sur.', $config->welcome_message);
        $this->assertSame('No me queda lugar ese día.', $config->no_availability_message);
        $this->assertSame('Se nos cayó el sistema, ya te atiende alguien.', $config->fallback_message);
    }

    /**
     * AC · Un texto vacío cae al valor por defecto, no manda un mensaje en blanco.
     *
     * El modo de falla es concreto: el dueño borra el texto para reescribirlo,
     * guarda sin querer, y el bot le contesta a sus clientes con un mensaje
     * vacío. El síntoma se ve en el chat del cliente, no en el panel.
     */
    public function test_un_texto_vacio_cae_al_valor_por_defecto(): void
    {
        $user = $this->entrarComo();
        $porDefecto = BusinessSetting::valoresPorDefecto();

        $this->put(self::URL, [
            'welcome_message' => '',
            'no_availability_message' => '   ',
            'fallback_message' => 'Este sí lo escribo yo.',
        ]);

        $config = $this->configDe($user);

        $this->assertSame($porDefecto['welcome_message'], $config->welcome_message);
        $this->assertSame($porDefecto['no_availability_message'], $config->no_availability_message);
        $this->assertSame('Este sí lo escribo yo.', $config->fallback_message);
    }

    /** El texto se guarda sin espacios sobrantes en los bordes. */
    public function test_recorta_los_espacios_de_los_bordes(): void
    {
        $user = $this->entrarComo();

        $this->put(self::URL, ['welcome_message' => "  Hola  \n"]);

        $this->assertSame('Hola', $this->configDe($user)->welcome_message);
    }

    /** Un texto desmedido se rechaza y no persiste nada. */
    public function test_rechaza_un_texto_demasiado_largo(): void
    {
        $user = $this->entrarComo();
        $original = $this->configDe($user)->welcome_message;

        $this->put(self::URL, ['welcome_message' => str_repeat('a', 1025)])
            ->assertInvalid(['welcome_message']);

        $this->assertSame($original, $this->configDe($user)->welcome_message);
    }

    // --------------------------------------------------------- autorizacion

    /** H-13 · `staff` no configura. */
    public function test_staff_no_puede_ver_ni_guardar(): void
    {
        $this->entrarComo(Role::Staff);

        $this->get(self::URL)->assertForbidden();
        $this->put(self::URL, ['welcome_message' => 'intento'])->assertForbidden();
    }

    /** `admin` sí. */
    public function test_admin_puede_configurar(): void
    {
        $user = $this->entrarComo(Role::Admin);

        $this->get(self::URL)->assertOk();
        $this->put(self::URL, ['welcome_message' => 'Cambiado por el encargado'])->assertRedirect();

        $this->assertSame('Cambiado por el encargado', $this->configDe($user)->welcome_message);
    }

    public function test_sin_login_redirige(): void
    {
        $this->get(self::URL)->assertRedirect('/login');
    }

    // ------------------------------------------------------------ aislamiento

    /** RNF-01 · Guardar en un tenant no toca al otro. */
    public function test_no_puede_modificar_los_mensajes_de_otro_tenant(): void
    {
        $otro = Tenant::create([
            'name' => 'Estética Norte', 'slug' => 'estetica-norte',
            'status' => 'active', 'timezone' => 'America/Bogota',
        ]);
        $textoAjeno = BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $otro->id)->first()->welcome_message;

        $user = $this->entrarComo(Role::Owner, 'peluqueria-sur');

        $this->put(self::URL, ['welcome_message' => 'Solo para mi negocio']);

        $this->assertSame('Solo para mi negocio', $this->configDe($user)->welcome_message);
        $this->assertSame(
            $textoAjeno,
            BusinessSetting::withoutTenantScope()->where('tenant_id', $otro->id)->first()->welcome_message,
            'Se modificaron los mensajes de otro tenant.'
        );
    }

    /** La pantalla muestra el texto vigente. */
    public function test_la_pantalla_muestra_el_texto_vigente(): void
    {
        $user = $this->entrarComo();
        $this->put(self::URL, ['welcome_message' => 'Texto que ya guardé']);

        $this->get(self::URL)->assertOk()->assertSee('Texto que ya guardé', escape: false);
    }
}
