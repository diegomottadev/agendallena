<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;

/**
 * T-020 · `tap` del canal estructurado.
 *
 * Formatea en JSON por linea (JSON Lines) y engancha los dos procesadores.
 *
 * **El orden importa y no es intercambiable:** `RedactSecrets` va ultimo para
 * que corra primero. Monolog aplica los procesadores en orden inverso al de
 * registro, y la redaccion tiene que ver el registro ya completo —incluidos los
 * campos que agrega `AddStructuredContext`— antes de que se serialice.
 */
class StructuredChannel
{
    public function __invoke(Logger $logger): void
    {
        /*
         * Los timestamps del log se escriben en la zona de `display_timezone`
         * —Buenos Aires por defecto— y **no** en UTC.
         *
         * No contradice RNF-02: el log es una superficie que lee una persona, no
         * un dato que se persiste. Leer un log en UTC mientras se depura un
         * problema de horarios obliga a hacer la resta de cabeza en cada línea,
         * que es justo cuando más caro sale equivocarse.
         *
         * El formato ISO conserva el offset (`-03:00`), así que la línea sigue
         * siendo un instante sin ambigüedad y se puede correlacionar con las
         * filas de la base, que están en UTC.
         */
        $logger->setTimezone(new \DateTimeZone((string) config('app.display_timezone', 'UTC')));

        foreach ($logger->getHandlers() as $handler) {
            // `includeStacktraces` para que una excepcion conserve su traza: sin
            // ella, "contar excepciones por integracion" no permite diagnosticar
            // ninguna. La traza pasa igual por la redaccion.
            $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true, true);
            $handler->setFormatter($formatter);
        }

        $logger->pushProcessor(new AddStructuredContext);
        $logger->pushProcessor(new RedactSecrets);
    }
}
