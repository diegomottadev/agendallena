<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Leads\FilaDeLead;
use App\Models\Integration;
use App\Models\LeadSpreadsheet;
use App\Services\Google\GoogleIntegracionVencida;
use App\Services\Google\PlanillaDeGoogle;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * T-044 · US-26 · La PyME elige **cuál** de sus planillas recibe los leads.
 *
 * Sin esta pantalla, el volcado de T-045 funciona y comercialmente no cumple:
 * los leads caen en un archivo que creamos nosotros, que es exactamente el CRM
 * nuevo que la PyME ya rechazó. La mitad de la propuesta de valor es *"sin
 * obligarte a cambiar de herramientas"*, y esa mitad se juega acá.
 *
 * ⚠️ **La URL de esta pantalla la fija este ticket.** Ningún documento la
 * nombraba; sigue la convención de T-015 y T-017 (`/panel/configuracion/...`
 * detrás de `rol:configurar`).
 *
 * ## La validación va antes de guardar, siempre
 *
 * Confirmar sin haber ido a Google es la peor salida posible: la dueña se va
 * tranquila y se entera de que no funcionaba el día que busca un lead y no está.
 * Por eso ningún camino de acá abajo escribe la fila sin haber preguntado
 * primero (AC-26.1, AC-26.3).
 */
class ConfiguracionPlanillaController extends Controller
{
    /** Cómo se ve el `spreadsheetId` adentro de la URL que copia el navegador. */
    private const PATRON_URL = '#/spreadsheets/d/([A-Za-z0-9_-]+)#';

    /** La hoja de una planilla que creamos nosotros. */
    private const HOJA_POR_DEFECTO = 'Leads';

    public function editar()
    {
        return view('panel.configuracion.planilla', [
            'tenant' => auth()->user()->tenant,
            'usuario' => auth()->user(),
            'vinculo' => LeadSpreadsheet::query()->first(),
            'encabezados' => FilaDeLead::ENCABEZADOS,
        ]);
    }

    /**
     * **AC-26.1.** La dueña pega la URL de su planilla y elige la hoja.
     */
    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'hoja' => ['required', 'string', 'max:191'],
        ], [], [
            'url' => 'URL de la planilla',
            'hoja' => 'hoja',
        ]);

        if (preg_match(self::PATRON_URL, $datos['url'], $m) !== 1) {
            return back()->withInput()->withErrors([
                'url' => 'Esa no parece la URL de una planilla de Google Sheets. '
                    .'Abrila en el navegador y copiá la dirección completa.',
            ]);
        }

        $spreadsheetId = $m[1];
        $hoja = trim($datos['hoja']);

        $google = $this->cuentaDeGoogle();

        if ($google === null) {
            return back()->withInput()->withErrors([
                'url' => 'Todavía no conectaste tu cuenta de Google. La planilla se lee con el '
                    .'mismo permiso que la agenda.',
            ]);
        }

        try {
            $metadata = app(PlanillaDeGoogle::class)->metadata($google, $spreadsheetId);
        } catch (GoogleIntegracionVencida) {
            return back()->withInput()->withErrors([
                'url' => 'Tu cuenta de Google dejó de estar conectada. Reconectala y volvé a '
                    .'intentar.',
            ]);
        }

        /*
         * **AC-26.3 · los dos errores son distintos porque se resuelven
         * distinto.** Uno es revisar la URL y el otro es compartir el archivo. Un
         * mensaje único obliga a la dueña a probar las dos cosas, y esa es la
         * clase de fricción con la que se abandona el setup.
         */
        if ($metadata->status() === 403) {
            return back()->withInput()->withErrors([
                'url' => 'No tenemos permiso sobre esa planilla. Compartila con '
                    ."«{$google->account_identifier}» dándole permiso de editor y volvé a intentar.",
            ]);
        }

        if ($metadata->status() === 404) {
            return back()->withInput()->withErrors([
                'url' => 'No encontramos ninguna planilla con esa dirección. Revisá que la URL '
                    .'sea la de la planilla y esté completa.',
            ]);
        }

        if (! $metadata->successful()) {
            Log::warning('Google Sheets no respondió al validar la planilla', [
                'tenant_id' => $google->tenant_id,
                'integracion' => 'google_sheets',
                'codigo' => 'SHEETS_VALIDACION_SIN_RESPUESTA',
                'http' => $metadata->status(),
            ]);

            return back()->withInput()->withErrors([
                'url' => 'Google no contestó y no pudimos comprobar la planilla. Probá de nuevo '
                    .'en un minuto.',
            ]);
        }

        /*
         * La hoja tiene que existir. Si no, cada lead se escribiría contra un
         * rango inválido: Google devuelve un 400, el cliente no se entera de
         * nada, y la dueña descubre la planilla vacía dentro de un mes.
         */
        $hojas = PlanillaDeGoogle::hojasDe($metadata);

        if ($hojas !== [] && ! in_array($hoja, $hojas, true)) {
            return back()->withInput()->withErrors([
                'hoja' => 'Esa planilla no tiene ninguna hoja llamada «'.$hoja.'». Tiene: '
                    .implode(', ', $hojas).'.',
            ]);
        }

        return $this->vincular($spreadsheetId, $hoja, $request);
    }

    /**
     * **AC-26.2.** La PyME que no tiene planilla pide que se le cree una.
     *
     * Es la mitad del KPI de setup: si el único camino fuera *"andá a Drive,
     * creala, copiá la URL"*, la vinculación se abandona en el paso dos.
     */
    public function crear(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:191'],
        ], [], ['nombre' => 'nombre de la planilla']);

        $google = $this->cuentaDeGoogle();

        if ($google === null) {
            return back()->withErrors([
                'nombre' => 'Todavía no conectaste tu cuenta de Google. La planilla se crea en su '
                    .'Drive.',
            ]);
        }

        $titulo = filled($datos['nombre'] ?? null)
            ? trim($datos['nombre'])
            : 'Leads · '.auth()->user()->tenant->name;

        try {
            $spreadsheetId = app(PlanillaDeGoogle::class)->crear(
                $google, $titulo, self::HOJA_POR_DEFECTO, FilaDeLead::ENCABEZADOS
            );
        } catch (GoogleIntegracionVencida) {
            return back()->withErrors([
                'nombre' => 'Tu cuenta de Google dejó de estar conectada. Reconectala y volvé a '
                    .'intentar.',
            ]);
        }

        if ($spreadsheetId === null) {
            return back()->withErrors([
                'nombre' => 'Google no pudo crear la planilla. Probá de nuevo en un minuto.',
            ]);
        }

        return $this->vincular($spreadsheetId, self::HOJA_POR_DEFECTO, $request);
    }

    /**
     * Deja **una** vinculación, la nueva (AC-26.4).
     *
     * ## El único de la base es el que decide, no un `if`
     *
     * Decisión § 11: dos negocios no pueden compartir planilla. La comprobación
     * podría hacerse con un `SELECT` previo y estaría mal: entre ese `SELECT` y
     * el `INSERT` hay una ventana en la que dos vinculaciones simultáneas pasan
     * las dos. Acá se intenta escribir y se traduce el rechazo del motor, que es
     * el único que no tiene ventana — la misma lección de `live_event_id`.
     */
    private function vincular(string $spreadsheetId, string $hoja, Request $request): RedirectResponse
    {
        $tenantId = $request->user()->tenant_id;

        try {
            LeadSpreadsheet::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                ['spreadsheet_id' => $spreadsheetId, 'sheet_name' => $hoja],
            );
        } catch (QueryException $e) {
            if (! $this->esPlanillaTomada($e)) {
                throw $e;
            }

            Log::warning('Se intentó vincular una planilla que ya usa otro negocio', [
                'tenant_id' => $tenantId,
                'user_id' => $request->user()->id,
                'integracion' => 'google_sheets',
                'codigo' => 'SHEETS_PLANILLA_COMPARTIDA',
            ]);

            return back()->withInput()->withErrors([
                'url' => 'Esa planilla ya la está usando otro negocio. Cada negocio necesita la '
                    .'suya: si dos comparten el archivo, los clientes de uno quedan a la vista '
                    .'del otro.',
            ]);
        }

        Log::info('Planilla de leads vinculada', [
            'tenant_id' => $tenantId,
            'user_id' => $request->user()->id,
            'integracion' => 'google_sheets',
            'codigo' => 'SHEETS_PLANILLA_VINCULADA',
        ]);

        return redirect()
            ->route('panel.configuracion.planilla')
            ->with('exito', 'Listo: los leads nuevos van a esa planilla, en la hoja «'.$hoja.'».');
    }

    /**
     * ¿El motor rechazó por el único de `spreadsheet_id`?
     *
     * Se distingue del resto de los fallos de base: un rechazo por duplicado es
     * una respuesta del producto —"esa planilla es de otro"— y cualquier otro
     * error de base es un problema nuestro que no se puede disfrazar de eso.
     *
     * Se busca el **nombre del índice** y no la columna: el mensaje de una
     * `QueryException` trae el SQL entero, donde `spreadsheet_id` aparece siempre
     * y convertiría cualquier violación de integridad en "la planilla es de otro".
     */
    private function esPlanillaTomada(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'spreadsheet_id_unique');
    }

    /**
     * La cuenta de Google del tenant.
     *
     * El scope `spreadsheets` viaja en el mismo consentimiento que Calendar
     * (`config/services.php`), así que no hay una integración de Sheets aparte y
     * no hay que re-autorizar a nadie.
     *
     * `Integration` no lleva Global Scope —la ingesta resuelve el tenant desde el
     * `phone_number_id`—, así que el filtro va explícito (RNF-01).
     */
    private function cuentaDeGoogle(): ?Integration
    {
        return Integration::query()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
            ->first();
    }
}
