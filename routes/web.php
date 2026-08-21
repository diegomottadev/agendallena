<?php

use App\Http\Controllers\Panel\AsistenciaController;
use App\Http\Controllers\Panel\ConfiguracionAgendaController;
use App\Http\Controllers\Panel\ConfiguracionMensajesController;
use App\Http\Controllers\Panel\ConfiguracionPlanillaController;
use App\Http\Controllers\Panel\ConversacionesController;
use App\Http\Controllers\Panel\LoginController;
use App\Http\Controllers\Panel\RecordatoriosFallidosController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * T-046 · Autenticación del panel.
 */
Route::get('/login', [LoginController::class, 'mostrar'])->name('login');
Route::post('/login', [LoginController::class, 'entrar'])->name('login.entrar');
Route::post('/logout', [LoginController::class, 'salir'])->name('logout');

/*
 * Todo el panel vive detrás del login. El middleware `EstablecerTenantDeLaSesion`
 * (grupo `web`) publica el tenant del usuario, así que las consultas de acá
 * abajo quedan filtradas por el Global Scope aunque no lo pidan.
 *
 * T-034 · AC-25.2 · `avisos-de-integracion` comparte `avisoIntegraciones` con
 * **todas** las vistas de acá abajo. Va en el grupo y no en cada controlador
 * porque el aviso tiene que verse desde cualquier pantalla, y armarlo pantalla
 * por pantalla hace que la próxima nazca sin él.
 */
Route::middleware(['auth', 'avisos-de-integracion'])->prefix('panel')->group(function () {

    Route::get('/', function () {
        $tenant = auth()->user()->tenant;

        return view('panel', [
            'tenant' => $tenant,
            /*
             * T-034 · AC-25.1 · **Siempre las tres**, incluida la que nunca se
             * configuró: si se listara solo lo que existe, "Sheets no está" y
             * "Sheets está sano" se verían igual —la pantalla vacía— y en el MVP
             * todos los tenants están en el primer caso.
             */
            'integraciones' => App\Panel\EstadoDeIntegraciones::de($tenant),
            'usuario' => auth()->user(),
        ]);
    })->name('panel');

    /*
     * T-047 · El listado de conversaciones y T-049 · su detalle, más las
     * acciones de T-025 (pausar/reactivar) y T-035 (resolver la derivación).
     *
     * Todo con `rol:atender` y no `rol:configurar`, que es de los **tres**
     * roles: un empleado que está atendiendo a mano tiene que poder callar al
     * bot y leer el historial de su cliente sin depender del dueño.
     * Definición: `.claude/docs/01-producto/05-roles-y-permisos.md`
     */
    Route::middleware('rol:atender')->prefix('conversaciones')->group(function () {
        Route::get('/', [ConversacionesController::class, 'index'])
            ->name('panel.conversaciones');

        /*
         * T-049 · El detalle con el historial. ⚠️ La URL la fija este ticket:
         * ningún documento la nombraba. Va **después** de las rutas con verbo
         * para que `{conversacion}` no se coma nada.
         */
        Route::get('{conversacion}', [ConversacionesController::class, 'mostrar'])
            ->name('panel.conversaciones.detalle');

        Route::post('{conversacion}/pausar', [ConversacionesController::class, 'pausar'])
            ->name('panel.conversaciones.pausar');
        Route::post('{conversacion}/reactivar', [ConversacionesController::class, 'reactivar'])
            ->name('panel.conversaciones.reactivar');

        /*
         * T-035 · AC-20.3 · Resolver la derivación devuelve la conversación al
         * flujo automático. Va con el mismo `rol:atender`: quien atiende al
         * cliente derivado es el que tiene que poder cerrarla.
         */
        Route::post('{conversacion}/resolver', [ConversacionesController::class, 'resolver'])
            ->name('panel.conversaciones.resolver');
    });

    /*
     * T-038 · Registro de asistencia. Va con `rol:atender` y no con
     * `rol:configurar`: quien sabe si el cliente vino es la persona que estuvo
     * en el mostrador. Si esto exigiera configurar, el dato lo carga quien no lo
     * tiene y el KPI de US-17 queda vacío.
     * Definición: `.claude/docs/01-producto/05-roles-y-permisos.md`
     */
    Route::middleware('rol:atender')->prefix('asistencia')->group(function () {
        Route::get('/', [AsistenciaController::class, 'index'])
            ->name('panel.asistencia');
        Route::post('{booking}/marcar', [AsistenciaController::class, 'marcar'])
            ->name('panel.asistencia.marcar');
    });

    /*
     * T-039 · Recordatorios que no salieron, y su reintento manual. Va con
     * `rol:atender` y no con `rol:configurar`, por el mismo motivo que la
     * asistencia: quien ve que a un cliente no le llegó el aviso es quien está
     * atendiendo, y es quien tiene que poder reintentarlo o llamarlo.
     * Definición: `.claude/docs/01-producto/05-roles-y-permisos.md`
     */
    Route::middleware('rol:atender')->prefix('recordatorios-fallidos')->group(function () {
        Route::get('/', [RecordatoriosFallidosController::class, 'index'])
            ->name('panel.recordatorios-fallidos');
        Route::post('{registro}/reintentar', [RecordatoriosFallidosController::class, 'reintentar'])
            ->name('panel.recordatorios-fallidos.reintentar');
    });

    /*
     * Configuración: solo `owner` y `admin`.
     * Definición: `.claude/docs/01-producto/05-roles-y-permisos.md`
     */
    Route::middleware('rol:configurar')->prefix('configuracion')->name('panel.configuracion.')->group(function () {
        // T-017 · Mensajes del bot.
        Route::get('mensajes', [ConfiguracionMensajesController::class, 'editar'])->name('mensajes');
        Route::put('mensajes', [ConfiguracionMensajesController::class, 'guardar'])->name('mensajes.guardar');

        // T-015 · Agenda del negocio.
        Route::get('agenda', [ConfiguracionAgendaController::class, 'editar'])->name('agenda');
        Route::put('agenda', [ConfiguracionAgendaController::class, 'guardar'])->name('agenda.guardar');

        /*
         * T-044 · La planilla de Google Sheets donde caen los leads.
         *
         * `crear` va **después** de las dos anteriores y con su propio verbo: es
         * el camino de AC-26.2, la PyME que todavía no tiene ninguna planilla.
         */
        Route::get('planilla', [ConfiguracionPlanillaController::class, 'editar'])->name('planilla');
        Route::put('planilla', [ConfiguracionPlanillaController::class, 'guardar'])->name('planilla.guardar');
        Route::post('planilla/crear', [ConfiguracionPlanillaController::class, 'crear'])->name('planilla.crear');
    });
});
