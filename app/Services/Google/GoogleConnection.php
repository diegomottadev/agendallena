<?php

namespace App\Services\Google;

use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * T-013 · Renovación transparente del `access_token` de Google.
 *
 * Un `access_token` de Google dura una hora. Sin esto, el producto deja de
 * funcionar cada mañana y el dueño de la PyME tiene que reconectar a mano —
 * lo que convierte el KPI de setup en un costo recurrente.
 *
 * Toda llamada a Google pasa por `ejecutar()`, que se ocupa del token para que
 * el código que consulta el calendario no sepa que existe el problema.
 */
class GoogleConnection
{
    /**
     * Margen antes del vencimiento real.
     *
     * Un token que vence en 30 segundos alcanza para pasar la comprobación y
     * vencer a mitad de la petición: renovar un minuto antes evita ese 401 que
     * después hay que resolver con el reintento.
     */
    private const MARGEN_SEGUNDOS = 60;

    public function __construct(private readonly GoogleOAuthClient $oauth) {}

    /**
     * Ejecuta una operación contra Google resolviendo el token.
     *
     * @template T
     *
     * @param  callable(string):T  $operacion  Recibe el `access_token` vigente.
     * @return T
     *
     * @throws GoogleIntegracionVencida  cuando no hay forma de obtener un token.
     */
    public function ejecutar(Integration $integration, callable $operacion): mixed
    {
        if ($integration->status === 'expired') {
            // AC: ante una integración vencida se deja de intentar. Reintentar
            // contra un permiso revocado solo suma latencia y ruido en el log.
            throw new GoogleIntegracionVencida($integration);
        }

        // Renovación proactiva: si ya sabemos que venció, no hace falta gastar
        // una llamada para que Google nos lo diga con un 401.
        if ($this->estaVencido($integration)) {
            $this->renovar($integration);
        }

        $resultado = $operacion((string) $integration->access_token);

        /*
         * Reintento **exactamente una vez**, y el criterio del ticket es
         * explícito: "no hay bucle de reintento". La garantía no está en una
         * variable de conteo sino en la estructura — este bloque no es un `while`
         * y no se puede volver a entrar en él.
         */
        if ($this->esNoAutorizado($resultado)) {
            $this->renovar($integration);

            $resultado = $operacion((string) $integration->access_token);
        }

        return $resultado;
    }

    /**
     * @throws GoogleIntegracionVencida
     */
    private function renovar(Integration $integration): void
    {
        $refresh = (string) $integration->refresh_token;

        if ($refresh === '') {
            /*
             * Sin `refresh_token` no hay nada que renovar: la autorización se
             * hizo sin `access_type=offline`, o se guardó incompleta. No se
             * recupera sola — hay que reconectar la cuenta.
             */
            $this->marcarVencida($integration, 'sin refresh_token');

            throw new GoogleIntegracionVencida($integration);
        }

        $resultado = $this->oauth->refrescarToken($refresh);

        if ($resultado === 'invalid_grant') {
            $this->marcarVencida($integration, 'invalid_grant: el permiso fue revocado');

            throw new GoogleIntegracionVencida($integration);
        }

        if (! is_array($resultado)) {
            /*
             * Fallo transitorio: **no** se marca `expired`. Marcarla acá haría
             * que un 500 de Google de treinta segundos deje al cliente
             * desconectado hasta que alguien lo note y reconecte a mano.
             */
            Log::warning('No se pudo renovar el token de Google, fallo transitorio', [
                'tenant_id' => $integration->tenant_id,
                'integracion' => 'google_calendar',
                'codigo' => 'OAUTH_REFRESH_TRANSITORIO',
            ]);

            throw new RuntimeException('No se pudo renovar el token de Google.');
        }

        $integration->access_token = $resultado['access_token'];

        /*
         * AC: si Google rota el `refresh_token`, el nuevo reemplaza al anterior.
         * Google no lo rota en cada refresco, así que llega ausente casi siempre
         * — y sobreescribirlo con vacío dejaría la integración sin forma de
         * renovar la próxima vez.
         */
        if (! empty($resultado['refresh_token'])) {
            $integration->refresh_token = $resultado['refresh_token'];
        }

        $integration->expires_at = isset($resultado['expires_in'])
            ? now()->addSeconds((int) $resultado['expires_in'])
            : null;
        $integration->status = 'connected';
        $integration->save();

        Log::info('Token de Google renovado', [
            'tenant_id' => $integration->tenant_id,
            'integracion' => 'google_calendar',
            'codigo' => 'OAUTH_REFRESH_OK',
            'rotó_refresh_token' => ! empty($resultado['refresh_token']),
        ]);
    }

    private function marcarVencida(Integration $integration, string $motivo): void
    {
        $integration->status = 'expired';
        $integration->save();

        /*
         * Este `status='expired'` es el insumo de AC-09.2 y de T-034: si no se
         * escribe acá, esos dos quedan sin dato que leer y el panel muestra la
         * integración como sana mientras el bot no puede agendar.
         */
        Log::error('Integración de Google vencida: se deja de intentar', [
            'tenant_id' => $integration->tenant_id,
            'integracion' => 'google_calendar',
            'codigo' => 'OAUTH_INTEGRACION_VENCIDA',
            'motivo' => $motivo,
        ]);
    }

    private function estaVencido(Integration $integration): bool
    {
        if (blank($integration->access_token)) {
            return true;
        }

        // Sin `expires_at` no se puede saber: se asume vigente y, si no lo está,
        // lo resuelve el reintento ante el 401.
        return $integration->expires_at !== null
            && $integration->expires_at->subSeconds(self::MARGEN_SEGUNDOS)->isPast();
    }

    /**
     * ¿La operación falló por token inválido?
     *
     * Acepta una respuesta de Laravel HTTP o cualquier objeto con `status()`,
     * para no atar este servicio a un cliente HTTP concreto.
     */
    private function esNoAutorizado(mixed $resultado): bool
    {
        return is_object($resultado)
            && method_exists($resultado, 'status')
            && $resultado->status() === 401;
    }
}
