<?php

namespace App\Meta;

use RuntimeException;

/**
 * T-033 · AC-09.3 · Meta nos frenó por volumen. **Es transitorio.**
 *
 * Se distingue de cualquier otro rechazo de Meta porque es el único que se
 * arregla solo esperando: un `400` por parámetro inválido reintentado tres veces
 * da tres veces el mismo `400`, mientras que un rate limit reintentado a los 5 y
 * a los 15 segundos suele pasar.
 *
 * Por eso **se lanza** en vez de devolver `null` como el resto de los rechazos:
 * es la única forma de que la cola de T-011 —`tries = 3`, `backoff() = [5, 15]`—
 * se entere de que hay algo que reintentar. Devolver `null` dejaría al cliente
 * sin el mensaje y sin nadie mirando.
 *
 * ## Qué NO hace
 *
 * No registra el fallo definitivo. El criterio pide que eso ocurra **solo al
 * agotar reintentos**, y quien sabe que se agotaron es `ProcessMessageJob::failed()`,
 * no este envío puntual. Registrar acá metería tres `ERROR` por cada mensaje que
 * después salió bien, y ese es justo el ruido que T-039 va a tener que filtrar.
 */
class MetaRateLimit extends RuntimeException
{
    public function __construct(
        public readonly string $tenantId,
        public readonly int $http,
        public readonly ?int $codigoDeMeta,
    ) {
        parent::__construct(
            "Meta limitó el envío del tenant {$tenantId} (HTTP {$http}, código {$codigoDeMeta}). "
            .'Es transitorio: se reintenta con el backoff de la cola.'
        );
    }
}
