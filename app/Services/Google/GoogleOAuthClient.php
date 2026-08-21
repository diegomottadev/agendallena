<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Http;

/**
 * T-012 · Cliente OAuth 2.0 de Google.
 *
 * ⚠️ Es un cliente **de Google**, no una abstracción de proveedores de
 * calendario, y es deliberado: si Outlook entra al MVP —decisión todavía abierta
 * en T-007— esto pasa a ser una implementación detrás de una interfaz. Construir
 * la abstracción ahora, sin el segundo proveedor a la vista, produce una interfaz
 * modelada sobre un solo caso, que es la peor forma de tener dos.
 */
class GoogleOAuthClient
{
    private const URL_AUTORIZACION = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const URL_TOKEN = 'https://oauth2.googleapis.com/token';

    private const URL_USERINFO = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /**
     * URL a la que se manda al cliente para que autorice.
     */
    public function urlDeAutorizacion(string $state): string
    {
        return self::URL_AUTORIZACION.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', array_merge(
                config('services.google.scopes'),
                // `email` para poder mostrar en el panel qué cuenta quedó
                // vinculada: sin esto, el indicador dice "conectado" y el cliente
                // no sabe con cuál de sus cuentas.
                ['openid', 'email']
            )),

            /*
             * `access_type=offline` es lo que hace que Google entregue un
             * `refresh_token`. Sin él solo llega un `access_token` de una hora, y
             * el bot deja de agendar cuando el cliente cierra el navegador —una
             * falla que aparece recién al día siguiente del alta.
             */
            'access_type' => 'offline',

            /*
             * `prompt=consent` fuerza a Google a devolver `refresh_token` incluso
             * si el cliente ya había autorizado antes. Google lo entrega **solo
             * la primera vez** salvo que se lo pidas explícitamente: sin esto,
             * reconectar una cuenta ya vinculada guarda un `refresh_token` vacío
             * y pisa el que funcionaba.
             */
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Canjea el código de autorización por los tokens.
     *
     * @return array<string,mixed>|null  `null` si Google rechaza el canje.
     */
    public function canjearCodigo(string $codigo): ?array
    {
        $respuesta = Http::asForm()->timeout(20)->post(self::URL_TOKEN, [
            'code' => $codigo,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        return $respuesta->successful() ? $respuesta->json() : null;
    }

    /**
     * T-013 · Canjea el `refresh_token` por un `access_token` nuevo.
     *
     * Devuelve los tokens, o un **código de error** como string. La distinción
     * importa y no es cosmética: `invalid_grant` significa que el permiso se
     * revocó y no se recupera reintentando —hay que marcar la integración como
     * vencida—, mientras que un 500 de Google o un timeout son transitorios y
     * marcar `expired` ahí mataría una integración que funciona.
     *
     * @return array<string,mixed>|string
     */
    public function refrescarToken(string $refreshToken): array|string
    {
        try {
            $respuesta = Http::asForm()->timeout(20)->post(self::URL_TOKEN, [
                'refresh_token' => $refreshToken,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'grant_type' => 'refresh_token',
            ]);
        } catch (\Throwable $e) {
            // Red caída o timeout: transitorio, nunca definitivo.
            return 'transitorio';
        }

        if ($respuesta->successful() && $respuesta->json('access_token')) {
            return $respuesta->json();
        }

        /*
         * Google devuelve 400 con `invalid_grant` cuando el permiso se revocó,
         * el refresh caducó por desuso, o el usuario cambió la contraseña. Es la
         * única respuesta que justifica dejar de intentar.
         */
        if ($respuesta->status() === 400 && $respuesta->json('error') === 'invalid_grant') {
            return 'invalid_grant';
        }

        return 'transitorio';
    }

    /**
     * Correo de la cuenta que autorizó, para mostrarlo en el panel (AC-04.1).
     */
    public function correoDeLaCuenta(string $accessToken): ?string
    {
        $respuesta = Http::withToken($accessToken)->timeout(20)->get(self::URL_USERINFO);

        return $respuesta->successful() ? ($respuesta->json('email') ?: null) : null;
    }
}
