<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * T-012 · `state` de OAuth: firmado y con el `tenant_id` adentro.
 *
 * Cumple dos funciones distintas que conviene no confundir:
 *
 * 1. **Anti-CSRF.** Sin `state`, un tercero puede inducir al navegador del
 *    cliente a completar un callback con *su* código de autorización, y el
 *    calendario que termina conectado al tenant es el del atacante.
 * 2. **Transporte del tenant.** El callback lo invoca Google, no nuestro panel:
 *    no hay sesión ni contexto de tenant. El `tenant_id` tiene que viajar en el
 *    `state` porque no hay otro lugar de donde sacarlo.
 *
 * Va **firmado y no encriptado**: el `tenant_id` no es secreto, lo que importa
 * es que nadie pueda fabricarlo. La firma es HMAC con `APP_KEY`.
 */
class GoogleOAuthState
{
    /** Ventana de vida del `state`. Un consentimiento tarda segundos, no horas. */
    private const VIGENCIA_SEGUNDOS = 900;

    public static function emitir(string $tenantId): string
    {
        $datos = [
            'tenant_id' => $tenantId,
            // `nonce` para que dos autorizaciones del mismo tenant en el mismo
            // segundo no produzcan el mismo `state`.
            'nonce' => Str::random(16),
            'exp' => now()->addSeconds(self::VIGENCIA_SEGUNDOS)->timestamp,
        ];

        $cuerpo = self::base64UrlEncode(json_encode($datos, JSON_THROW_ON_ERROR));

        return $cuerpo.'.'.self::firmar($cuerpo);
    }

    /**
     * Devuelve el `tenant_id` si el `state` es válido, o `null` si no lo es.
     *
     * Nunca lanza: un `state` inválido es una condición esperable —un enlace
     * viejo, un intento de CSRF— y el llamador tiene que poder responder con una
     * pantalla, no con un 500.
     */
    public static function verificar(?string $state): ?string
    {
        if (! is_string($state) || ! str_contains($state, '.')) {
            return self::rechazar('formato invalido');
        }

        [$cuerpo, $firma] = explode('.', $state, 2);

        // Tiempo constante: una comparación normal filtra la firma carácter a
        // carácter ante un atacante que mida tiempos.
        if (! hash_equals(self::firmar($cuerpo), $firma)) {
            return self::rechazar('firma no coincide');
        }

        $datos = json_decode((string) self::base64UrlDecode($cuerpo), true);

        if (! is_array($datos) || ! isset($datos['tenant_id'], $datos['exp'])) {
            return self::rechazar('contenido incompleto');
        }

        if (now()->timestamp > (int) $datos['exp']) {
            return self::rechazar('vencido');
        }

        return (string) $datos['tenant_id'];
    }

    private static function rechazar(string $motivo): null
    {
        // El `state` recibido no se registra: puede ser uno válido de otro
        // tenant capturado de un log compartido.
        Log::warning('Callback de Google rechazado por state invalido', [
            'motivo' => $motivo,
            'integracion' => 'google_calendar',
            'codigo' => 'OAUTH_STATE_INVALIDO',
        ]);

        return null;
    }

    private static function firmar(string $cuerpo): string
    {
        return hash_hmac('sha256', $cuerpo, (string) config('app.key'));
    }

    private static function base64UrlEncode(string $valor): string
    {
        return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $valor): string|false
    {
        return base64_decode(strtr($valor, '-_', '+/'), true);
    }
}
