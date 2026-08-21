<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * T-046 · El tenant del usuario autenticado se publica en el contexto en
         * cada request web. Es lo que hace que el Global Scope de
         * `BelongsToTenant` filtre sin que cada consulta tenga que acordarse.
         *
         * Va en el grupo `web` y no en `api`: los webhooks de Meta no tienen
         * usuario, y el callback de Google resuelve el tenant desde el `state`.
         */
        $middleware->web(append: [
            App\Http\Middleware\EstablecerTenantDeLaSesion::class,
        ]);

        $middleware->alias([
            'rol' => App\Http\Middleware\ExigirRol::class,

            /*
             * T-034 · AC-25.2 · El aviso de integración caída se comparte con
             * todas las vistas del panel. Va como alias del grupo del panel y no
             * en `web`: necesita usuario autenticado, y en el login no hay.
             */
            'avisos-de-integracion' => App\Http\Middleware\CompartirAvisosDeIntegracion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
