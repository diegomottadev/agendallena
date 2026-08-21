<?php

namespace App\Http\Middleware;

use App\Panel\EstadoDeIntegraciones;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-034 · AC-25.2 · El aviso de integración caída, visible desde cualquier pantalla.
 *
 * ## Por qué acá y no en cada controlador
 *
 * El dueño entra al panel a marcar asistencia o a mirar una conversación, no a
 * revisar integraciones. Si el aviso viviera solo en la pantalla de
 * integraciones se enteraría cuando ya fue.
 *
 * Armarlo en cada controlador tiene además un modo de falla conocido: **la
 * próxima pantalla nace sin el aviso** y nadie se da cuenta hasta que un cliente
 * llama. Compartido acá, cualquier vista del panel lo tiene por existir.
 *
 * Va como middleware del grupo del panel y no como composer global: necesita un
 * usuario autenticado, y sin él no hay tenant del que leer nada.
 */
class CompartirAvisosDeIntegracion
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()?->tenant;

        View::share(
            'avisoIntegraciones',
            $tenant === null ? [] : EstadoDeIntegraciones::avisos($tenant),
        );

        return $next($request);
    }
}
