<?php

namespace App\Support;

use RuntimeException;

/**
 * Tenant activo del proceso actual.
 *
 * Existe porque el aislamiento por `tenant_id` (RNF-01) tiene que valer tambien
 * fuera de un request HTTP. Los jobs, las tareas del scheduler y los comandos de
 * artisan **no heredan el tenant**: hay que aplicarlo antes de la primera query,
 * y este es el lugar donde se declara.
 *
 * ⚠️ Hoy nadie lo puebla automaticamente: la autenticacion del panel es T-046 y
 * todavia no existe. Hasta entonces, cada job y cada seeder lo setea a mano con
 * `runAs()`. Cuando llegue T-046, el middleware de sesion lo setea por request.
 */
class TenantContext
{
    private static ?string $tenantId = null;

    public static function set(?string $tenantId): void
    {
        self::$tenantId = $tenantId;
    }

    public static function get(): ?string
    {
        return self::$tenantId;
    }

    public static function has(): bool
    {
        return self::$tenantId !== null;
    }

    public static function forget(): void
    {
        self::$tenantId = null;
    }

    public static function idOrFail(): string
    {
        if (self::$tenantId === null) {
            throw new RuntimeException(
                'No hay tenant activo. Envolve la operacion en TenantContext::runAs($tenantId, ...) '
                .'o usa el scope explicito. Un modelo con aislamiento por tenant no puede consultarse '
                .'sin saber de que PyME son los datos.'
            );
        }

        return self::$tenantId;
    }

    /**
     * Corre un bloque con un tenant activo y restaura el anterior al salir.
     *
     * Restaura tambien si el callback lanza: si no, una excepcion dejaria el
     * proceso con el tenant equivocado activo y las queries siguientes leerian
     * datos de otra PyME — exactamente la falla silenciosa que RNF-01 evita.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public static function runAs(string $tenantId, callable $callback): mixed
    {
        $anterior = self::$tenantId;
        self::$tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            self::$tenantId = $anterior;
        }
    }
}
