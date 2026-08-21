<?php

namespace App\Providers;

use App\Meta\MemoriaDePlantillas;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Singleton y no `new` por llamada: es lo que hace que dos preguntas
         * seguidas sobre la misma cuenta de WhatsApp cuesten una sola lectura de
         * `integrations`. Del contenedor y no de una propiedad estática para que
         * muera con la aplicación —una corrida de artisan, un request— y el
         * proceso siguiente vuelva a leer de la base.
         */
        $this->app->singleton(MemoriaDePlantillas::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
