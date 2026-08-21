<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Un tenant con **los tres roles** y un segundo tenant, para probar a mano lo
 * que los tests automatizados no muestran: cómo se ve el panel con cada permiso.
 *
 * El segundo tenant no es decorativo. El modo de falla que más importa en
 * multi-tenancy por columna es **ver datos de otra PyME**, y con un solo tenant
 * en la base ese error es invisible: toda consulta sin filtro parece correcta.
 *
 * ⚠️ **Solo para desarrollo.** Contraseñas conocidas y correos de fantasía; no
 * correr contra una base con clientes reales.
 *
 *   php artisan db:seed --class=RolesDemoSeeder
 */
class RolesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('RolesDemoSeeder no corre en producción: crea usuarios con contraseñas conocidas.');

            return;
        }

        $tenants = new TenantSeeder;
        $tenants->setCommand($this->command);

        $tenants->crearTenant(
            slug: 'peluqueria-sur',
            nombre: 'Peluquería Sur',
            timezone: 'America/Argentina/Buenos_Aires',
            usuarios: [
                ['duenia@peluqueria-sur.test', 'Ana (dueña)', Role::Owner],
                ['encargado@peluqueria-sur.test', 'Bruno (encargado)', Role::Admin],
                ['empleada@peluqueria-sur.test', 'Carla (equipo)', Role::Staff],
            ],
        );

        /*
         * Mismo correo del owner en otro tenant, a propósito: es el caso que el
         * único global de `email` hacía imposible (H-14) y que la migración de
         * T-046 destraba. Si este seeder falla con "Duplicate entry", el único
         * volvió a ser global.
         */
        $tenants->crearTenant(
            slug: 'estetica-norte',
            nombre: 'Estética Norte',
            // Argentina tambien: en desarrollo todo habla en hora de Buenos
            // Aires. Que el sistema funcione con husos distintos lo verifica
            // `ZonaHorariaTest`, que compara Buenos Aires, Bogota y Mexico.
            timezone: 'America/Argentina/Cordoba',
            usuarios: [
                ['duenia@peluqueria-sur.test', 'Ana (también acá)', Role::Owner],
                ['empleado@estetica-norte.test', 'Diego (equipo)', Role::Staff],
            ],
        );

        $this->command?->newLine();
        $this->command?->info('Todos con contraseña: password');
    }
}
