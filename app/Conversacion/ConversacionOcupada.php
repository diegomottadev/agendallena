<?php

namespace App\Conversacion;

use App\Models\Conversation;
use RuntimeException;

/**
 * Otro proceso está transicionando esta misma conversación ahora mismo.
 *
 * Pasa cuando el cliente manda dos mensajes en ráfaga y caen en dos workers. **No
 * se espera al lock**: encolar el segundo detrás del primero produciría dos
 * respuestas seguidas, que es la falla que el lock viene a evitar. Lo habitual
 * es descartar el mensaje: la deduplicación de T-011 ya garantizó que no se
 * pierda nada que importe.
 */
class ConversacionOcupada extends RuntimeException
{
    public function __construct(public readonly Conversation $conversacion)
    {
        parent::__construct(
            "La conversación {$conversacion->id} está siendo transicionada por otro proceso."
        );
    }
}
