<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\Google\GoogleOAuthClient;
use App\Services\Google\GoogleOAuthState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * T-012 · Conexión OAuth 2.0 con Google Calendar.
 *
 * Es el primer momento de fricción del cliente y el que más cuesta si falla:
 * sin esto resuelto, cada alta consume horas de soporte.
 */
class GoogleOAuthController extends Controller
{
    public function __construct(private readonly GoogleOAuthClient $google) {}

    /**
     * Manda al cliente a la pantalla de consentimiento de Google.
     *
     * El tenant sale del **usuario autenticado**, no de un parámetro: la ruta va
     * detrás de `auth` y `rol:configurar` (T-046). Conectar el calendario es una
     * actividad de `owner`/`admin` — B2 del story map.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $tenant = $request->user()?->tenant;

        if ($tenant === null) {
            return redirect()->route('panel', ['google' => 'error', 'motivo' => 'tenant_desconocido']);
        }

        return redirect()->away(
            $this->google->urlDeAutorizacion(GoogleOAuthState::emitir($tenant->id))
        );
    }

    /**
     * Vuelta de Google. Lo invoca el navegador del cliente, no nuestro panel.
     */
    public function callback(Request $request): RedirectResponse
    {
        /*
         * AC-04.3 · El cliente cerró o rechazó la pantalla de consentimiento.
         * Google devuelve `error=access_denied`. No es una falla del sistema: se
         * vuelve al panel con el indicador en rojo y cómo reintentar.
         */
        if ($request->query('error')) {
            Log::info('El cliente no completó el consentimiento de Google', [
                'integracion' => 'google_calendar',
                'codigo' => 'OAUTH_CANCELADO',
                'motivo' => $request->query('error'),
            ]);

            return redirect('/panel?google=cancelado');
        }

        // El `state` se verifica **antes** de tocar el código: un callback sin
        // `state` válido no merece ni una llamada a Google.
        $tenantId = GoogleOAuthState::verificar($request->query('state'));

        if ($tenantId === null) {
            return redirect('/panel?google=error&motivo=state_invalido');
        }

        $codigo = (string) $request->query('code', '');

        if ($codigo === '') {
            return redirect('/panel?google=error&motivo=sin_codigo');
        }

        $tokens = $this->google->canjearCodigo($codigo);

        if ($tokens === null || empty($tokens['access_token'])) {
            Log::error('Google rechazó el canje del código de autorización', [
                'tenant_id' => $tenantId,
                'integracion' => 'google_calendar',
                'codigo' => 'OAUTH_CANJE_FALLIDO',
            ]);

            return redirect('/panel?google=error&motivo=canje_fallido');
        }

        $this->guardarIntegracion($tenantId, $tokens);

        return redirect('/panel?google=conectado');
    }

    /**
     * @param  array<string,mixed>  $tokens
     */
    private function guardarIntegracion(string $tenantId, array $tokens): void
    {
        $correo = $this->google->correoDeLaCuenta($tokens['access_token']) ?? 'cuenta de Google';

        /*
         * `Integration` no lleva Global Scope de tenant —la ingesta lo resuelve
         * *a partir* del `phone_number_id`, o sea que consulta antes de saber de
         * qué tenant se trata—, así que el filtro va explícito.
         */
        $integration = Integration::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
            ->first() ?? new Integration;

        $integration->tenant_id = $tenantId;
        $integration->provider = Integration::PROVIDER_GOOGLE_CALENDAR;
        $integration->account_identifier = $correo;
        $integration->access_token = $tokens['access_token'];

        /*
         * Google **no reenvía el `refresh_token`** en una reautorización si ya lo
         * había entregado antes. `prompt=consent` hace que sí lo mande, pero si
         * aun así llegara vacío, se conserva el anterior: pisarlo con `null`
         * dejaría la integración viva hasta que venza el access_token y muerta
         * después, sin forma de recuperarse sola.
         */
        if (! empty($tokens['refresh_token'])) {
            $integration->refresh_token = $tokens['refresh_token'];
        }

        $integration->expires_at = isset($tokens['expires_in'])
            ? now()->addSeconds((int) $tokens['expires_in'])
            : null;
        $integration->status = 'connected';
        $integration->save();

        Log::info('Calendario de Google conectado', [
            'tenant_id' => $tenantId,
            'integracion' => 'google_calendar',
            'codigo' => 'OAUTH_CONECTADO',
            'cuenta' => $correo,
            'con_refresh_token' => ! empty($integration->refresh_token),
        ]);
    }

}
