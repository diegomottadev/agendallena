<?php

namespace App\Services\Google;

use App\Models\Integration;
use RuntimeException;

/**
 * La integración de Google no se puede usar y **no se recupera reintentando**:
 * el permiso fue revocado o nunca hubo `refresh_token`.
 *
 * Es un resultado tipado y no una excepción genérica a propósito (RNF-03): quien
 * la recibe tiene que responderle al cliente con el mensaje de cortesía del
 * tenant, no dejar el chat colgado. Se distingue de un fallo transitorio, que sí
 * conviene reintentar.
 */
class GoogleIntegracionVencida extends RuntimeException
{
    public function __construct(public readonly Integration $integration)
    {
        parent::__construct(
            "La integración de Google del tenant {$integration->tenant_id} está vencida. "
            .'Hay que reconectar la cuenta desde el panel.'
        );
    }
}
