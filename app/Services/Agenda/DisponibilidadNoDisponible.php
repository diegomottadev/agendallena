<?php

namespace App\Services\Agenda;

use App\Models\Tenant;
use RuntimeException;

/**
 * No se pudo saber qué está libre, así que **no se ofrece nada**.
 *
 * Se lanza en vez de devolver la grilla completa cuando Google no responde. La
 * alternativa —asumir "todo libre"— haría que el bot ofrezca horarios ocupados,
 * que según el contexto de T-022 es *"peor que no ofrecer nada: rompe la
 * confianza en el primer contacto"*.
 *
 * Quien la recibe le contesta al cliente con el mensaje de cortesía del tenant
 * (RNF-03), no con un chat colgado.
 */
class DisponibilidadNoDisponible extends RuntimeException
{
    public function __construct(public readonly Tenant $tenant)
    {
        parent::__construct(
            "No se pudo consultar la disponibilidad del tenant {$tenant->id}. "
            .'No se ofrecen horarios: es preferible no responder con turnos a responder con turnos ocupados.'
        );
    }
}
