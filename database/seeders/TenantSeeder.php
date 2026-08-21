<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Alta completa de una PyME: tenant, usuario dueño y configuración.
 *
 * Existe porque **el alta por pantalla es v2** (B1.1 y B1.4 del story map están
 * marcadas 🕳️ v2) y durante los pilotos las cuentas las damos de alta nosotros.
 *
 * ⚠️ **Esto es exactamente lo que T-015 advierte:** si la carga inicial la
 * hacemos nosotros, el KPI de *"setup en menos de 60 minutos"* lo cumplimos
 * nosotros y no el cliente. El número sería cierto y engañoso a la vez.
 * **Medí cuánto tardás** dando de alta un cliente completo: ese dato es el que
 * decide si las pantallas de configuración valen sus 5 puntos.
 *
 * Idempotente: se puede correr las veces que haga falta.
 *
 *   php artisan db:seed --class=TenantSeeder
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $this->crearTenant(
            slug: 'piloto',
            nombre: 'Piloto AgendaLlena',
            timezone: 'America/Argentina/Buenos_Aires',
            usuarios: [
                ['owner@agendallena.test', 'Dueño del Piloto', Role::Owner],
            ],
        );
    }

    /**
     * @param  array<int,array{0:string,1:string,2:Role}>  $usuarios
     */
    public function crearTenant(
        string $slug,
        string $nombre,
        string $timezone,
        array $usuarios,
        string $password = 'password',
    ): Tenant {
        $tenant = Tenant::firstOrCreate(
            ['slug' => $slug],
            ['name' => $nombre, 'status' => 'trial', 'timezone' => $timezone]
        );

        /*
         * `Tenant::created` crea la configuración por defecto (T-014), pero solo
         * la primera vez. Si el tenant ya existía de antes de esa migración,
         * quedó sin fila y el motor de disponibilidad no ofrecería un turno.
         */
        $configExistente = BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)->exists();

        if (! $configExistente) {
            BusinessSetting::withoutTenantScope()->create(
                ['tenant_id' => $tenant->id] + BusinessSetting::valoresPorDefecto()
            );
            $this->command?->info("  configuración por defecto creada para [{$slug}]");
        }

        foreach ($usuarios as [$email, $nombreUsuario, $rol]) {
            $user = User::firstOrNew(['tenant_id' => $tenant->id, 'email' => $email]);

            // La contraseña no se pisa si el usuario ya existe: correr el seeder
            // de nuevo no puede resetear la clave de alguien que ya la cambió.
            if (! $user->exists) {
                $user->password = Hash::make($password);
            }

            $user->tenant_id = $tenant->id;
            $user->name = $nombreUsuario;
            $user->role = $rol;
            $user->save();

            $this->command?->info("  usuario [{$email}] · {$rol->etiqueta()}");
        }

        $this->command?->info("Tenant [{$slug}] listo.");

        return $tenant;
    }
}
