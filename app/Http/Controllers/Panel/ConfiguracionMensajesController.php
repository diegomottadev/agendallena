<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * T-017 · Configuración de los mensajes del bot.
 *
 * RNF-03 exige un mensaje de cortesía configurable y RF-A6 una bienvenida con el
 * nombre del negocio. Los dos textos son **la voz del cliente frente a su propio
 * cliente**, y hasta acá estaban en el código.
 *
 * El Global Scope de `BelongsToTenant` resuelve el tenant desde la sesión: no
 * hace falta —ni conviene— filtrarlo a mano.
 */
class ConfiguracionMensajesController extends Controller
{
    public function editar()
    {
        return view('panel.configuracion.mensajes', [
            'config' => BusinessSetting::firstOrFail(),
            'tenant' => auth()->user()->tenant,
            'usuario' => auth()->user(),
            'porDefecto' => BusinessSetting::valoresPorDefecto(),
        ]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'welcome_message' => ['nullable', 'string', 'max:1024'],
            'fallback_message' => ['nullable', 'string', 'max:1024'],
            'no_availability_message' => ['nullable', 'string', 'max:1024'],
        ], [], [
            'welcome_message' => 'mensaje de bienvenida',
            'fallback_message' => 'mensaje de fallback',
            'no_availability_message' => 'mensaje de sin disponibilidad',
        ]);

        $config = BusinessSetting::firstOrFail();
        $porDefecto = BusinessSetting::valoresPorDefecto();

        /*
         * AC · Un texto vacío cae al valor por defecto en vez de enviar un
         * mensaje en blanco. El modo de falla que esto evita es concreto: el
         * dueño borra el texto para reescribirlo, guarda sin querer, y a partir
         * de ahí el bot le contesta a sus clientes con un mensaje vacío. El
         * síntoma se ve en el chat del cliente, no en el panel.
         */
        foreach (array_keys($datos) as $campo) {
            $config->{$campo} = filled($datos[$campo])
                ? trim($datos[$campo])
                : $porDefecto[$campo];
        }

        $config->save();

        /*
         * AC · El cambio aplica en la conversación siguiente sin reiniciar el
         * worker ni desplegar. Se cumple por construcción: el worker lee
         * `business_settings` en cada mensaje y no cachea nada. Si en algún
         * momento se agrega caché de configuración, **hay que invalidarla acá**.
         */
        Log::info('Mensajes del bot actualizados', [
            'tenant_id' => $config->tenant_id,
            'user_id' => $request->user()->id,
            'codigo' => 'CONFIG_MENSAJES_ACTUALIZADA',
        ]);

        return redirect()
            ->route('panel.configuracion.mensajes')
            ->with('exito', 'Los mensajes se guardaron. El bot los usa a partir de la conversación siguiente.');
    }
}
