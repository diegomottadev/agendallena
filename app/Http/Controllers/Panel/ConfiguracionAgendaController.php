<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarAgendaRequest;
use App\Models\BusinessSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * T-015 · Configuración de agenda: días, horario, duración, buffer y zona.
 *
 * **Recorte Pareto aplicado:** un solo rango horario para todos los días de
 * atención. La tabla soporta jornada partida —`business_hours` es JSON con
 * lista de rangos por día— pero la pantalla expone un rango único: el turno
 * mañana/tarde aparece después del primer cliente. **Cuando se retome, no hay
 * que migrar nada.**
 */
class ConfiguracionAgendaController extends Controller
{
    public function editar()
    {
        $config = BusinessSetting::firstOrFail();

        return view('panel.configuracion.agenda', [
            'config' => $config,
            'tenant' => auth()->user()->tenant,
            'usuario' => auth()->user(),
            'dias' => $this->diasSeleccionados($config),
            'rango' => $this->rangoVigente($config),
            'zonas' => $this->zonasHorarias(),
        ]);
    }

    public function guardar(GuardarAgendaRequest $request): RedirectResponse
    {
        $datos = $request->validated();
        $config = BusinessSetting::firstOrFail();

        /*
         * Se reconstruye el JSON completo: los días que no vienen quedan con
         * lista vacía, que es como se representa "cerrado". Hacer un merge
         * dejaría abiertos los días que el dueño acaba de destildar.
         */
        $horarios = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $dia) {
            $horarios[$dia] = in_array($dia, $datos['dias'], true)
                ? [[$datos['apertura'], $datos['cierre']]]
                : [];
        }

        $config->business_hours = $horarios;
        $config->slot_duration_minutes = $datos['slot_duration_minutes'];
        $config->buffer_minutes = $datos['buffer_minutes'];
        $config->save();

        /*
         * AC-15.3 · La zona horaria vive en `tenants.timezone`, no acá: la usan
         * también los recordatorios y la conversión de Google. Guardarla en dos
         * lugares sería garantizar que algún día divergen.
         */
        $tenant = auth()->user()->tenant;

        if ($tenant->timezone !== $datos['timezone']) {
            $tenant->timezone = $datos['timezone'];
            $tenant->save();
        }

        Log::info('Agenda del negocio actualizada', [
            'tenant_id' => $config->tenant_id,
            'user_id' => $request->user()->id,
            'codigo' => 'CONFIG_AGENDA_ACTUALIZADA',
            'dias' => count($datos['dias']),
            'timezone' => $datos['timezone'],
        ]);

        return redirect()
            ->route('panel.configuracion.agenda')
            ->with('exito', 'La agenda se guardó. El bot la usa a partir de la conversación siguiente.');
    }

    /** @return array<int,string> */
    private function diasSeleccionados(BusinessSetting $config): array
    {
        return array_values(array_filter(
            ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            fn (string $d) => $config->abreEl($d)
        ));
    }

    /**
     * Rango a mostrar en el formulario de rango único.
     *
     * ⚠️ Si el tenant tuviera jornada partida cargada —hoy solo posible por
     * seeder—, la pantalla muestra **el primer rango** y guardar aplastaría el
     * segundo. Es la consecuencia aceptada del recorte, y se avisa en la vista.
     *
     * @return array{0:string,1:string}
     */
    private function rangoVigente(BusinessSetting $config): array
    {
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $dia) {
            $rangos = $config->rangosDe($dia);

            if ($rangos !== []) {
                return [$rangos[0][0], $rangos[0][1]];
            }
        }

        return ['09:00', '18:00'];
    }

    public function tieneJornadaPartida(BusinessSetting $config): bool
    {
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $dia) {
            if (count($config->rangosDe($dia)) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zonas horarias del mercado objetivo primero, el resto de América después.
     *
     * Elegir mal la zona rompe **todos** los horarios de forma silenciosa: los
     * turnos se ofrecen corridos y los recordatorios llegan a la hora
     * equivocada, sin ningún error visible. Con 145 entradas alfabéticas y una
     * etiqueta como `Argentina/Buenos Aires`, equivocarse es fácil.
     *
     * Las diez primeras cubren las ciudades donde vive el mercado del lean
     * canvas. El resto queda accesible para el caso que no previmos.
     *
     * @return array<string,array<string,string>>
     */
    private function zonasHorarias(): array
    {
        $frecuentes = [
            'America/Argentina/Buenos_Aires' => 'Buenos Aires (Argentina)',
            'America/Argentina/Cordoba' => 'Córdoba (Argentina)',
            'America/Argentina/Mendoza' => 'Mendoza (Argentina)',
            'America/Montevideo' => 'Montevideo (Uruguay)',
            'America/Santiago' => 'Santiago (Chile)',
            'America/Asuncion' => 'Asunción (Paraguay)',
            'America/La_Paz' => 'La Paz (Bolivia)',
            'America/Lima' => 'Lima (Perú)',
            'America/Bogota' => 'Bogotá (Colombia)',
            'America/Mexico_City' => 'Ciudad de México (México)',
            'America/Sao_Paulo' => 'São Paulo (Brasil)',
        ];

        $resto = [];

        foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::AMERICA) as $z) {
            if (! isset($frecuentes[$z])) {
                $resto[$z] = str_replace(['America/', '_'], ['', ' '], $z);
            }
        }

        $resto['UTC'] = 'UTC';

        return [
            'Más usadas' => $frecuentes,
            'Todas las demás' => $resto,
        ];
    }
}
