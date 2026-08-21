<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * T-020 · Ningun secreto llega al log, ni parcialmente.
 *
 * ⚠️ Importa mas de lo habitual: en multi-tenancy por columna, un token filtrado
 * en un log compartido es acceso a la cuenta de Google de un cliente.
 *
 * Redacta por tres vias, porque ninguna sola alcanza:
 *
 * 1. **Por nombre de clave** (`access_token`, `authorization`, ...). Cubre lo
 *    que alguien pasa deliberadamente en el contexto.
 * 2. **Por valor conocido**: los secretos que estan configurados se buscan
 *    literalmente en el texto. Cubre el caso que la via 1 no ve — un token
 *    incrustado en el mensaje de una excepcion, por ejemplo, que es como se
 *    filtran en la practica.
 * 3. **Por forma**: tokens de Meta (`EAA...`), firmas `sha256=`, cabeceras
 *    `Bearer`. Cubre secretos que **no** son los nuestros: el token de otro
 *    tenant, o uno rotado hace cinco minutos que ya no esta en la config.
 */
class RedactSecrets implements ProcessorInterface
{
    private const REEMPLAZO = '[REDACTADO]';

    /** Claves cuyo valor nunca se registra, sin importar que contengan. */
    private const CLAVES_SENSIBLES = [
        'access_token', 'refresh_token', 'verify_token', 'app_secret',
        'client_secret', 'token', 'secret', 'password', 'authorization',
        'x-hub-signature-256', 'signature', 'firma', 'api_key',
    ];

    /** Formas de secreto reconocibles aunque no sean nuestras. */
    private const PATRONES = [
        // Token de usuario o de sistema de Meta.
        '/\bEAA[A-Za-z0-9]{20,}/',
        // Firma del webhook, con o sin prefijo.
        '/\bsha256=[a-f0-9]{64}\b/i',
        '/\bBearer\s+[A-Za-z0-9._\-]{20,}/i',
        // Refresh token de Google.
        '/\b1\/\/[A-Za-z0-9._\-]{20,}/',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $valores = $this->valoresConfigurados();

        return $record->with(
            message: $this->limpiarTexto($record->message, $valores),
            context: $this->limpiarArray($record->context, $valores),
            extra: $this->limpiarArray($record->extra, $valores),
        );
    }

    /**
     * Secretos que la aplicacion conoce. Se buscan literalmente en el texto.
     *
     * @return array<int,string>
     */
    private function valoresConfigurados(): array
    {
        $candidatos = [
            config('services.meta.app_secret'),
            config('services.meta.access_token'),
            config('services.meta.verify_token'),
            config('services.google.client_secret'),
            config('app.key'),
        ];

        // Se descartan los cortos: un valor de pocos caracteres produciria
        // reemplazos espurios en cualquier texto que lo contenga por casualidad.
        return array_values(array_filter(
            $candidatos,
            fn ($v) => is_string($v) && strlen($v) >= 8
        ));
    }

    /**
     * @param  array<int|string,mixed>  $datos
     * @param  array<int,string>  $valores
     * @return array<int|string,mixed>
     */
    private function limpiarArray(array $datos, array $valores): array
    {
        $limpio = [];

        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && $this->esClaveSensible($clave)) {
                $limpio[$clave] = self::REEMPLAZO;

                continue;
            }

            $limpio[$clave] = match (true) {
                is_array($valor) => $this->limpiarArray($valor, $valores),
                is_string($valor) => $this->limpiarTexto($valor, $valores),
                // Una excepcion en el contexto arrastra su mensaje y su traza,
                // que es justo donde suelen viajar los tokens.
                $valor instanceof \Throwable => $this->limpiarTexto(
                    $valor::class.': '.$valor->getMessage(), $valores
                ),
                default => $valor,
            };
        }

        return $limpio;
    }

    private function esClaveSensible(string $clave): bool
    {
        $normalizada = strtolower(str_replace(['-', ' '], '_', $clave));

        foreach (self::CLAVES_SENSIBLES as $sensible) {
            if (str_contains($normalizada, str_replace('-', '_', $sensible))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $valores
     */
    private function limpiarTexto(string $texto, array $valores): string
    {
        foreach ($valores as $valor) {
            $texto = str_replace($valor, self::REEMPLAZO, $texto);
        }

        return (string) preg_replace(self::PATRONES, self::REEMPLAZO, $texto);
    }
}
