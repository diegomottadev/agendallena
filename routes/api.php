<?php

use App\Http\Controllers\Api\GoogleOAuthController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Middleware\VerifyMetaSignature;
use Illuminate\Support\Facades\Route;

/*
 * T-012 · OAuth con Google.
 *
 * Viven bajo `/api/v1` porque es la URI que quedó registrada como redirect
 * autorizado en Google Cloud, y Google exige coincidencia **exacta**: moverlas a
 * `web.php` obligaría a reconfigurar la consola. El ticket las nombra
 * `/oauth/google/*` de forma indicativa.
 *
 * Llevan el grupo `web` a propósito, aunque vivan en `api.php`: necesitan sesión
 * —el `redirect` para saber quién es el usuario, y el `callback` para volver al
 * panel logueado.
 */
Route::prefix('v1/oauth/google')->middleware('web')->group(function () {
    /*
     * T-046 · Conectar el calendario es una actividad de `owner`/`admin` (B2 del
     * story map). El tenant sale del usuario autenticado y no de un parámetro.
     */
    Route::get('redirect', [GoogleOAuthController::class, 'redirect'])
        ->middleware(['auth', 'rol:configurar'])
        ->name('oauth.google.redirect');

    /*
     * El callback **no** lleva `auth`: lo invoca el navegador viniendo de Google
     * y la sesión puede haberse perdido en el ida y vuelta. Su autenticación es
     * el `state` firmado, que además transporta el tenant.
     */
    Route::get('callback', [GoogleOAuthController::class, 'callback'])
        ->name('oauth.google.callback');
});

/*
 * Webhooks de Meta Cloud API.
 *
 * Viven en el grupo `api`, que no lleva sesion ni CSRF: Meta no manda cookies
 * ni token CSRF, y con el middleware de sesion el POST de T-010 devolveria 419.
 */
Route::prefix('v1/webhooks')->group(function () {
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify'])
        ->name('webhooks.whatsapp.verify');

    /*
     * La firma se valida en middleware, no en el controlador: asi ningun payload
     * sin autenticar llega a tocar codigo de aplicacion. El GET del handshake no
     * lo lleva a proposito — Meta no firma la verificacion, la autentica con el
     * `verify_token`.
     */
    Route::post('whatsapp', [WhatsAppWebhookController::class, 'receive'])
        ->middleware(VerifyMetaSignature::class)
        ->name('webhooks.whatsapp.receive');
});
