<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Roles y permisos — cierra H-13.
 * Definición: `.claude/docs/01-producto/05-roles-y-permisos.md`
 */
class RolesYPermisosTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'PyME '.$slug, 'slug' => $slug,
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
    }

    private function usuario(Tenant $tenant, Role $rol, ?string $email = null): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $rol->etiqueta(),
            'email' => $email ?? $rol->value.'@'.$tenant->slug.'.test',
            'password' => 'secreto123',
            'role' => $rol,
        ]);
    }

    /** Configurar es de owner y admin; staff no. */
    public function test_solo_owner_y_admin_configuran(): void
    {
        $t = $this->tenant('a');

        $this->assertTrue($this->usuario($t, Role::Owner)->puedeConfigurar());
        $this->assertTrue($this->usuario($t, Role::Admin)->puedeConfigurar());
        $this->assertFalse($this->usuario($t, Role::Staff)->puedeConfigurar(),
            'Staff puede tocar la configuracion del negocio: rompe H-13.');
    }

    /** Gestionar usuarios es solo de owner: la única diferencia con admin. */
    public function test_solo_owner_gestiona_usuarios(): void
    {
        $t = $this->tenant('a');

        $this->assertTrue($this->usuario($t, Role::Owner)->puedeGestionarUsuarios());
        $this->assertFalse($this->usuario($t, Role::Admin)->puedeGestionarUsuarios(),
            'Un admin puede crear usuarios: un encargado que se va se lleva el acceso.');
        $this->assertFalse($this->usuario($t, Role::Staff)->puedeGestionarUsuarios());
    }

    /** Atender y supervisar es de los tres, incluida la pausa del bot. */
    public function test_los_tres_roles_atienden(): void
    {
        $t = $this->tenant('a');

        foreach (Role::cases() as $rol) {
            $this->assertTrue($rol->puedeAtender(), "El rol {$rol->value} no puede atender.");
        }
    }

    /** El rol por defecto es el más restrictivo. */
    public function test_el_rol_por_defecto_es_staff(): void
    {
        $t = $this->tenant('a');

        $id = DB::table('users')->insertGetId([
            'tenant_id' => $t->id, 'name' => 'Sin rol',
            'email' => 'sinrol@a.test', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(Role::Staff, User::find($id)->role,
            'Un alta que olvida el rol otorga mas permisos de los debidos.');
    }

    /** Un usuario pertenece a un tenant y lo sabe. */
    public function test_el_usuario_reconoce_su_tenant(): void
    {
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $owner = $this->usuario($a, Role::Owner);

        $this->assertTrue($owner->perteneceA($a->id));
        $this->assertFalse($owner->perteneceA($b->id),
            'Un owner del tenant A se reconoce como parte del tenant B.');
    }

    /** H-14 · El mismo correo puede existir en dos tenants distintos. */
    public function test_el_mismo_correo_puede_estar_en_dos_tenants(): void
    {
        $a = $this->tenant('a');
        $b = $this->tenant('b');

        $this->usuario($a, Role::Owner, 'gestor@contable.test');
        $this->usuario($b, Role::Owner, 'gestor@contable.test');

        $this->assertSame(2, User::where('email', 'gestor@contable.test')->count());
    }

    /** Pero no dos veces dentro del mismo tenant. */
    public function test_el_mismo_correo_no_se_repite_dentro_de_un_tenant(): void
    {
        $a = $this->tenant('a');
        $this->usuario($a, Role::Owner, 'repetido@a.test');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->usuario($a, Role::Staff, 'repetido@a.test');
    }

    /** El seeder de demo deja los tres roles y dos tenants. */
    public function test_el_seeder_de_demo_arma_los_tres_roles_y_dos_tenants(): void
    {
        $this->seed(\Database\Seeders\RolesDemoSeeder::class);

        $this->assertSame(2, Tenant::count());

        $sur = Tenant::where('slug', 'peluqueria-sur')->first();
        $roles = User::where('tenant_id', $sur->id)->pluck('role')->map(fn ($r) => $r->value)->sort()->values();

        $this->assertSame(['admin', 'owner', 'staff'], $roles->all());

        // Cada tenant nace con su configuración utilizable.
        $this->assertSame(2, DB::table('business_settings')->count());
    }

    /** El seeder es idempotente y no resetea contraseñas ya existentes. */
    public function test_el_seeder_se_puede_correr_dos_veces(): void
    {
        $this->seed(\Database\Seeders\RolesDemoSeeder::class);
        $hashOriginal = User::where('email', 'encargado@peluqueria-sur.test')->first()->password;

        $this->seed(\Database\Seeders\RolesDemoSeeder::class);

        $this->assertSame(2, Tenant::count());
        $this->assertSame(5, User::count());
        $this->assertSame($hashOriginal, User::where('email', 'encargado@peluqueria-sur.test')->first()->password,
            'Correr el seeder de nuevo resetea la clave de un usuario existente.');
    }
}
