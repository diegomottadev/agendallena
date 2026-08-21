<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-046 · Publica el tenant del usuario autenticado en el contexto.
 *
 * Es lo que hace que el Global Scope de `BelongsToTenant` funcione en el panel:
 * hasta acá, el contexto lo seteaba a mano cada job. Con esto, **una consulta
 * escrita sin filtro explícito queda filtrada igual** — el criterio de T-046.
 *
 * Se limpia al terminar el request. En un worker de larga vida —`octane`, o el
 * `artisan serve` que usamos en desarrollo— el proceso sobrevive entre
 * peticiones: dejar el tenant colgado haría que el request siguiente, de otro
 * usuario, arrancara con el tenant del anterior.
 */
class EstablecerTenantDeLaSesion
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario?->tenant_id) {
            TenantContext::set($usuario->tenant_id);
        }

        try {
            return $next($request);
        } finally {
            TenantContext::forget();
        }
    }
}
