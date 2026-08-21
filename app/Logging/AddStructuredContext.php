<?php

namespace App\Logging;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * T-020 · Campos fijos en toda entrada del log.
 *
 * El criterio del ticket es que el `tenant_id` aparezca **sin que el llamador
 * tenga que pasarlo**: si dependiera de que cada `Log::info()` se acuerde, el
 * conteo de excepciones por tenant seria incompleto justo en los caminos de
 * error, que son los que menos se revisan al escribirlos.
 *
 * Campos que agrega:
 *
 * | Campo            | Para que |
 * | :--------------- | :------- |
 * | `tenant_id`      | De que PyME es la entrada. `null` fuera de contexto de tenant |
 * | `canal`          | `http`, `queue` o `console` — de donde salio |
 * | `correlacion`    | Une todas las entradas de un mismo request o job |
 * | `integracion`    | `meta_whatsapp`, `google_calendar`, ... cuando aplica |
 * | `codigo`         | Codigo estable del evento, para contar sin depender del texto |
 */
class AddStructuredContext implements ProcessorInterface
{
    private static ?string $correlacion = null;

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        /*
         * `get()` y no `idOrFail()`: el logger no puede lanzar. Una entrada
         * emitida fuera de contexto de tenant —el arranque, un comando de
         * plataforma— vale `null`, que es un dato, no un error.
         */
        $extra['tenant_id'] = TenantContext::get();
        $extra['canal'] = $this->canal();
        $extra['correlacion'] = $this->correlacion();

        // `integracion` y `codigo` los pone quien loguea, via contexto. Se
        // normalizan a null para que toda entrada tenga las mismas claves y la
        // consulta por integracion no tenga que contemplar ausencias.
        $extra['integracion'] ??= $record->context['integracion'] ?? null;
        $extra['codigo'] ??= $record->context['codigo'] ?? null;

        return $record->with(extra: $extra);
    }

    private function canal(): string
    {
        if (app()->runningInConsole()) {
            // El worker de colas tambien corre en consola: se distingue por el
            // contexto que Laravel deja al procesar un job.
            return Context::has('job_id') ? 'queue' : 'console';
        }

        return 'http';
    }

    /**
     * Identificador que une todas las entradas de un mismo request o job.
     *
     * Sin esto, reconstruir que le paso a un mensaje puntual obliga a cruzar por
     * timestamp, que con varios workers en paralelo no alcanza.
     */
    private function correlacion(): string
    {
        if (Context::has('correlacion')) {
            return (string) Context::get('correlacion');
        }

        return self::$correlacion ??= (string) Str::uuid();
    }

    /** Reinicia la correlacion. Lo usa el worker al empezar cada job. */
    public static function nuevaCorrelacion(): void
    {
        self::$correlacion = (string) Str::uuid();
    }
}
