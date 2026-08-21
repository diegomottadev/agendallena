<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMessageJob;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de Meta: handshake de verificacion (T-008) e ingesta (T-010).
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * T-010 · Ingesta asincrona.
     *
     * La firma ya fue validada por el middleware `VerifyMetaSignature`: si el
     * request llego hasta aca, el cuerpo es autentico.
     *
     * Este metodo tiene que ser aburrido a proposito. Meta reintenta y despues
     * **desactiva** las suscripciones que responden lento, asi que lo unico que
     * pasa dentro del ciclo del request es decodificar y encolar. Cualquier
     * consulta a la base o llamada a un tercero que se agregue aca consume el
     * presupuesto de 200 ms y termina costando la suscripcion.
     */
    public function receive(Request $request): Response
    {
        // T-021 · Se marca el instante **al entrar**: es el punto desde el que se
        // mide el KPI de 15 segundos punta a punta (AC-16.1).
        $recibidoEn = microtime(true);

        $payload = json_decode($request->getContent(), true);

        /*
         * Con firma valida el cuerpo deberia ser JSON siempre. Si no lo es, no
         * hay nada que encolar — pero se responde 200 igual: devolver un error
         * haria que Meta reintente un payload que nunca va a mejorar.
         */
        if (! is_array($payload)) {
            Log::warning('Webhook de Meta con firma valida pero cuerpo no decodificable', [
                'bytes_cuerpo' => strlen($request->getContent()),
            ]);

            return response('', Response::HTTP_OK);
        }

        ProcessMessageJob::dispatch($payload, $recibidoEn);

        return response('', Response::HTTP_OK);
    }

    public function verify(Request $request): Response
    {
        /*
         * Meta manda `hub.mode`, `hub.verify_token` y `hub.challenge`.
         * PHP reemplaza los puntos por guiones bajos al parsear el query string,
         * asi que del lado nuestro llegan como `hub_mode`, `hub_verify_token` y
         * `hub_challenge`. Buscarlos con el punto devuelve null siempre.
         */
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if ($mode !== 'subscribe' || $token === '' || $challenge === '') {
            $this->logFailedAttempt($request, $token, 'parametros incompletos o mode invalido');

            return response('', Response::HTTP_FORBIDDEN);
        }

        $integration = $this->resolveIntegrationByVerifyToken($token);

        // AC-01.2 y AC-01.3: un token que no corresponde a ningun tenant no
        // devuelve el challenge, y el token del tenant A no valida al tenant B.
        if ($integration === null) {
            $this->logFailedAttempt($request, $token, 'ningun tenant coincide');

            return response('', Response::HTTP_FORBIDDEN);
        }

        Log::info('Handshake de webhook verificado', [
            'tenant_id' => $integration->tenant_id,
            'integration_id' => $integration->id,
        ]);

        /*
         * AC-01.1: el challenge sale tal cual, en text/plain. Devolverlo como JSON
         * —con comillas alrededor— hace que Meta rechace la verificacion.
         */
        return response($challenge, Response::HTTP_OK)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * El `verify_token` es por tenant: no hay token compartido.
     *
     * Se recorren las integraciones de Meta y se compara en memoria porque
     * `settings` esta encriptado y no se puede filtrar por SQL. El handshake
     * ocurre una vez por tenant al configurar el webhook, no por mensaje, asi
     * que el costo es irrelevante y no justifica un indice ni un hash aparte.
     */
    private function resolveIntegrationByVerifyToken(string $token): ?Integration
    {
        $match = null;

        Integration::query()
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->each(function (Integration $integration) use ($token, &$match): bool {
                $stored = $integration->settings['verify_token'] ?? null;

                // Comparacion en tiempo constante: una comparacion normal filtra
                // el token caracter a caracter ante un atacante que mida tiempos.
                if (is_string($stored) && $stored !== '' && hash_equals($stored, $token)) {
                    $match = $integration;

                    return false;
                }

                return true;
            });

        return $match;
    }

    /**
     * Registro de intentos fallidos con timestamp, IP y token recibido.
     *
     * El token va enmascarado a proposito: un intento fallido puede contener el
     * token valido de otro tenant (un copy-paste cruzado), y el ticket exige que
     * los verify_token no aparezcan en los logs. Los cuatro primeros caracteres
     * alcanzan para reconocer cual se uso sin dejarlo utilizable.
     */
    private function logFailedAttempt(Request $request, string $token, string $reason): void
    {
        Log::warning('Handshake de webhook rechazado', [
            'ip' => $request->ip(),
            'reason' => $reason,
            'token_recibido' => $token === ''
                ? '(vacio)'
                : mb_substr($token, 0, 4).'…('.mb_strlen($token).' chars)',
        ]);
    }
}
