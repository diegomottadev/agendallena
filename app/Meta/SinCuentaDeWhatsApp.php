<?php

namespace App\Meta;

use RuntimeException;

/**
 * T-050 · La PyME no tiene cargada su cuenta de WhatsApp (`waba_id`).
 *
 * Existe para que el fallo diga **qué falta**. Sin esta excepción, el camino es
 * pegarle a Meta con una URL a la que le falta un segmento y recibir de vuelta
 * un error de la plataforma que no menciona ni al tenant ni al dato ausente:
 * alguien tiene que leer la documentación de Graph para descubrir que el alta
 * quedó a medias.
 */
class SinCuentaDeWhatsApp extends RuntimeException
{
    public static function paraElTenant(string $tenantId): self
    {
        return new self(
            "La integración de Meta del tenant {$tenantId} no tiene `waba_id` en "
            .'`integrations.settings`. Con una cuenta de WhatsApp por PyME, sin el `waba_id` del '
            .'cliente no hay dónde crear ni consultar sus plantillas.'
        );
    }
}
