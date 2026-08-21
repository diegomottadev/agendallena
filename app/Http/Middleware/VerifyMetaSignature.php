<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * T-010 · Validacion de `X-Hub-Signature-256` sobre el cuerpo crudo.
 *
 * Es piso de seguridad: el ticket declara explicitamente que la firma **no se
 * recorta nunca**. Sin esto, cualquiera que conozca la URL publica del webhook
 * puede encolar trabajo en nuestra cola.
 *
 * ⚠️ El ticket dejaba abierto si la validacion corre en el request o se difiere
 * al worker, y mandaba resolverlo "con el resultado del arnes" — pero el arnes
 * de carga quedo diferido por el recorte Pareto, asi que ese metodo no existe.
 * Se resuelve por la regla ya cerrada en la skill `meta-cloud-api`: valida en el
 * request, antes de cualquier parseo. Diferirla al worker significaria encolar
 * trabajo no autenticado, que es justo lo que la firma existe para impedir.
 */
class VerifyMetaSignature
{
    private const HEADER = 'X-Hub-Signature-256';

    private const PREFIJO = 'sha256=';

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $secret = (string) config('services.meta.app_secret');

        /*
         * Falla cerrado. Un `.env` sin META_APP_SECRET no puede convertir el
         * webhook en un endpoint publico que encola lo que le manden: es el modo
         * de falla peligroso, porque el sistema "anda" y nadie lo nota.
         */
        if ($secret === '') {
            Log::error('Webhook de Meta rechazado: META_APP_SECRET no esta configurado', [
                'ip' => $request->ip(),
            ]);

            return $this->rechazar();
        }

        $recibida = (string) $request->header(self::HEADER, '');

        if (! $this->tieneFormatoValido($recibida)) {
            $this->registrarIntento($request, 'cabecera ausente o con formato invalido');

            return $this->rechazar();
        }

        /*
         * `getContent()` devuelve el cuerpo tal cual llego. Es deliberado no usar
         * `$request->all()` ni `json()`: Laravel normaliza el JSON al parsearlo
         * —espaciado, escapes unicode, orden— y la firma de Meta se calcula sobre
         * el byte exacto que viajo. Validar sobre el reserializado falla siempre.
         */
        $esperada = self::PREFIJO.hash_hmac('sha256', $request->getContent(), $secret);

        // Tiempo constante: `==` filtra la firma caracter a caracter ante un
        // atacante que mida tiempos de respuesta.
        if (! hash_equals($esperada, $recibida)) {
            $this->registrarIntento($request, 'firma no coincide');

            return $this->rechazar();
        }

        return $next($request);
    }

    /**
     * `sha256=` seguido de 64 caracteres hexadecimales.
     *
     * El formato no es secreto, asi que validarlo antes no filtra nada; sirve
     * para que `hash_equals` no reciba basura y para distinguir en el log una
     * cabecera rota de una firma que no coincide.
     */
    private function tieneFormatoValido(string $firma): bool
    {
        return (bool) preg_match('/^sha256=[a-f0-9]{64}$/', $firma);
    }

    private function rechazar(): Response
    {
        return response('', Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Registro del intento fallido (AC-02.2).
     *
     * **La firma recibida no se registra, ni enmascarada.** A diferencia del
     * `verify_token` de T-008 —donde los primeros caracteres ayudan a reconocer
     * un copy-paste cruzado— aca un prefijo no aporta nada diagnostico y la regla
     * del proyecto es que ninguna credencial aparezca en el log ni parcialmente.
     */
    private function registrarIntento(Request $request, string $motivo): void
    {
        Log::warning('Webhook de Meta rechazado por firma', [
            'ip' => $request->ip(),
            'motivo' => $motivo,
            'bytes_cuerpo' => strlen($request->getContent()),
        ]);
    }
}
