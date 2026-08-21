<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\CarbonInterface;

/**
 * Conversión de UTC a la hora del tenant, para mostrar (RNF-02).
 *
 * La regla del proyecto tiene dos mitades y solo una está en la base:
 *
 * 1. **La base guarda UTC, siempre.** Un instante es un instante; guardarlo en
 *    hora local haría que la misma fila signifique momentos distintos según la
 *    PyME, y en los países con horario de verano habría horas ambiguas y horas
 *    inexistentes.
 * 2. **Nada se le muestra a nadie en UTC.** Ni al dueño en el panel, ni al
 *    cliente por WhatsApp, ni en un recordatorio.
 *
 * Esta clase es la segunda mitad. Todo lo que se le muestre a un humano pasa
 * por acá — si aparece un `->format()` directo sobre una columna de fecha en
 * una vista o en un mensaje saliente, es un bug de RNF-02.
 */
class HoraLocal
{
    /**
     * Fecha y hora en la zona del tenant.
     *
     * Ejemplo: `martes 26 de agosto a las 15:30`, que es el formato que
     * necesitan los recordatorios de T-037.
     */
    public static function completa(CarbonInterface $instante, Tenant $tenant): string
    {
        return self::en($instante, $tenant)
            ->locale('es')
            ->isoFormat('dddd D [de] MMMM [a las] HH:mm');
    }

    /** Solo la hora: `15:30`. Para las listas de horarios de T-023. */
    public static function hora(CarbonInterface $instante, Tenant $tenant): string
    {
        return self::en($instante, $tenant)->format('H:i');
    }

    /** Fecha corta: `26/08/2026 15:30`. Para las tablas del panel. */
    public static function corta(CarbonInterface $instante, Tenant $tenant): string
    {
        return self::en($instante, $tenant)->format('d/m/Y H:i');
    }

    /**
     * El instante convertido, sin formatear.
     *
     * `copy()` porque `Carbon` es mutable y `setTimezone` modificaría el
     * original: quien nos pasó el modelo se encontraría con el atributo
     * cambiado sin haberlo pedido.
     */
    public static function en(CarbonInterface $instante, Tenant $tenant): CarbonInterface
    {
        return $instante->copy()->setTimezone($tenant->timezone);
    }
}
