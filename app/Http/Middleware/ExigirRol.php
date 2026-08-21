<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * T-046 · Autorización por permiso, del lado del servidor.
 *
 * Ocultar un botón en Vue no es un permiso: es una sugerencia. Toda ruta de
 * configuración pasa por acá.
 *
 * Se declara por **permiso** y no por lista de roles —`->middleware('rol:configurar')`
 * en vez de `->middleware('rol:owner,admin')`— para que agregar un rol o mover
 * una atribución se haga en un solo lugar, el enum `Role`, y no en cada ruta.
 *
 * Definición completa: `.claude/docs/01-producto/05-roles-y-permisos.md`
 */
class ExigirRol
{
    public function handle(Request $request, Closure $next, string $permiso): Response
    {
        $usuario = $request->user();

        $autorizado = match ($permiso) {
            'configurar' => (bool) $usuario?->puedeConfigurar(),
            'gestionar-usuarios' => (bool) $usuario?->puedeGestionarUsuarios(),
            'atender' => $usuario !== null,
            default => false,
        };

        if (! $autorizado) {
            /*
             * Se registra el intento, igual que exige AC-14.2 para el acceso
             * cruzado entre tenants: un permiso denegado que no deja rastro no
             * se puede auditar ni distinguir de un bug de la interfaz.
             */
            Log::warning('Acceso denegado por rol insuficiente', [
                'tenant_id' => $usuario?->tenant_id,
                'user_id' => $usuario?->id,
                'rol' => $usuario?->role?->value,
                'permiso_exigido' => $permiso,
                'ruta' => $request->path(),
                'ip' => $request->ip(),
                'codigo' => 'AUTZ_ROL_INSUFICIENTE',
            ]);

            throw new AccessDeniedHttpException('No tenés permiso para esta acción.');
        }

        return $next($request);
    }
}
