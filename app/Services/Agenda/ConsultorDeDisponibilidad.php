<?php

namespace App\Services\Agenda;

use App\Models\BusinessSetting;
use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Google\GoogleConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * T-022 · Horarios realmente libres: grilla del negocio menos lo ocupado.
 *
 * Es el corazón técnico de "sin cambiar tus herramientas": se lee el calendario
 * del cliente tal como lo usa hoy. **Ofrecer un horario ocupado es peor que no
 * ofrecer nada** — rompe la confianza en el primer contacto y obliga al equipo a
 * hacer justo el trabajo manual que vinimos a eliminar.
 *
 * ## Sin caché, por medición
 *
 * El ticket advertía que si el spike T-005 concluía que hacía falta caché, esto
 * crecía y aparecía una dependencia con T-027 para invalidarlo. **La medición
 * dijo que no**: `freeBusy` da p50 395 ms y p95 440 ms, el 29 % del presupuesto.
 * Se consulta en vivo y no hay caché que invalidar.
 *
 * ## Una sola llamada para toda la ventana
 *
 * El mismo spike encontró que **el tamaño de la ventana no afecta la latencia**:
 * 7 días, 30 y 90 miden igual (395 / 400 / 388 ms). El costo es la ida y vuelta,
 * no el volumen. Por eso se pide el rango completo de una vez en lugar de una
 * llamada por día — siete llamadas costarían siete veces más para el mismo dato.
 */
class ConsultorDeDisponibilidad
{
    private const URL_FREEBUSY = 'https://www.googleapis.com/calendar/v3/freeBusy';

    public function __construct(private readonly GoogleConnection $google) {}

    /**
     * Horarios libres en la ventana configurada, **con la causa si no hay**.
     *
     * T-024 · Devuelve un `ResultadoDisponibilidad` y no un array: un array
     * vacío no puede distinguir "está lleno" de "nadie configuró el horario", y
     * AC-19.4 exige que no se comuniquen igual.
     */
    public function consultar(
        Integration $integration,
        Tenant $tenant,
        BusinessSetting $config,
        CarbonImmutable $desde,
        ?int $dias = null,
    ): ResultadoDisponibilidad {
        // La ventana sale de la configuración del negocio (T-024): dejarla como
        // constante implícita fue la decisión abierta que este ticket cierra.
        $dias ??= $config->search_window_days;

        $generador = new GeneradorDeHorarios($config);
        $tz = $tenant->timezone;

        /*
         * Se pregunta por la configuración **antes** de mirar la grilla. Un
         * negocio sin días de atención produce cero candidatos, igual que uno
         * con la agenda llena — y de ahí sale la confusión que AC-19.4 prohíbe.
         * Preguntarlo acá, sobre la configuración y no sobre el resultado, deja
         * la distinción fuera de toda duda.
         */
        if (! $this->tieneAlgunDiaDeAtencion($config)) {
            Log::warning('Consulta de disponibilidad sobre un negocio sin días de atención configurados', [
                'tenant_id' => $tenant->id,
                'codigo' => 'AGENDA_SIN_CONFIGURAR',
            ]);

            return ResultadoDisponibilidad::sinConfiguracion();
        }

        $candidatos = $this->grillaDeLaVentana($generador, $desde, $dias, $tz);

        if ($candidatos === []) {
            /*
             * Configurado pero sin candidatos: la ventana cayó entera en días
             * cerrados, o los horarios de hoy ya pasaron. Para el cliente es lo
             * mismo que estar lleno, y **no se le pregunta a Google**: evita una
             * llamada de 400 ms para descartar un array vacío.
             */
            return ResultadoDisponibilidad::agendaLlena($dias);
        }

        $ocupados = $this->bloquesOcupados($integration, $tenant, $desde, $dias);
        $libres = $this->restarOcupados($candidatos, $ocupados, $config);

        return $libres === []
            ? ResultadoDisponibilidad::agendaLlena($dias)
            : ResultadoDisponibilidad::conHorarios($libres, $dias);
    }

    /**
     * Horarios libres, sin la causa.
     *
     * Se conserva para quien solo necesita la lista — T-023 renderizándola, por
     * ejemplo. Quien tenga que **contestarle algo al cliente** usa `consultar()`.
     *
     * @return array<int,CarbonImmutable>
     */
    public function horariosLibres(
        Integration $integration,
        Tenant $tenant,
        BusinessSetting $config,
        CarbonImmutable $desde,
        ?int $dias = null,
    ): array {
        return $this->consultar($integration, $tenant, $config, $desde, $dias)->horarios;
    }

    private function tieneAlgunDiaDeAtencion(BusinessSetting $config): bool
    {
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $dia) {
            if ($config->abreEl($dia)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Grilla de todos los días de la ventana, ya filtrada por días de atención.
     *
     * AC-05.3 sale de acá: si el negocio atiende de 09:00 a 18:00 de lunes a
     * viernes, `GeneradorDeHorarios` no produce nada fuera de eso, así que ningún
     * filtro posterior puede dejar pasar un horario inválido.
     *
     * @return array<int,CarbonImmutable>
     */
    private function grillaDeLaVentana(
        GeneradorDeHorarios $generador,
        CarbonImmutable $desde,
        int $dias,
        string $tz,
    ): array {
        $candidatos = [];
        $ahora = CarbonImmutable::now($tz);

        for ($i = 0; $i < $dias; $i++) {
            $dia = $desde->setTimezone($tz)->addDays($i);

            foreach ($generador->paraElDia($dia, $tz) as $horario) {
                // Un horario que ya pasó no se ofrece. Sin esto, a las 15:00 el
                // bot seguiría ofreciendo las 09:00 de hoy.
                if ($horario->greaterThan($ahora)) {
                    $candidatos[] = $horario;
                }
            }
        }

        return $candidatos;
    }

    /**
     * Bloques ocupados según Google, como pares de instantes.
     *
     * Incluye **todo** lo que ocupa el calendario, no solo lo que agendamos
     * nosotros: eso es lo que cubre UC-2.8, el horario que la PyME bloquea a
     * mano desde su celular.
     *
     * @return array<int,array{0:CarbonImmutable,1:CarbonImmutable}>
     */
    private function bloquesOcupados(
        Integration $integration,
        Tenant $tenant,
        CarbonImmutable $desde,
        int $dias,
    ): array {
        $respuesta = $this->google->ejecutar($integration, fn (string $token) => Http::withToken($token)
            ->timeout(20)
            ->post(self::URL_FREEBUSY, [
                // La ventana viaja en UTC (RNF-02); `timeZone` es para que Google
                // interprete los límites de día si hiciera falta.
                'timeMin' => $desde->utc()->toRfc3339String(),
                'timeMax' => $desde->utc()->addDays($dias)->toRfc3339String(),
                'timeZone' => $tenant->timezone,
                'items' => [['id' => 'primary']],
            ]));

        if (! $respuesta->successful()) {
            /*
             * Se propaga en vez de devolver "todo libre". Si Google falla y
             * respondiéramos con la grilla completa, el bot ofrecería horarios
             * ocupados — exactamente el peor resultado posible según el contexto
             * de este ticket. Que el chat conteste el mensaje de cortesía de
             * RNF-03 es mucho mejor que agendar sobre un turno existente.
             */
            Log::error('freeBusy no respondió: no se puede saber qué está libre', [
                'tenant_id' => $tenant->id,
                'integracion' => 'google_calendar',
                'codigo' => 'FREEBUSY_FALLIDO',
                'http' => $respuesta->status(),
            ]);

            throw new DisponibilidadNoDisponible($tenant);
        }

        $bloques = [];

        foreach ($respuesta->json('calendars.primary.busy') ?? [] as $b) {
            $bloques[] = [
                CarbonImmutable::parse($b['start']),
                CarbonImmutable::parse($b['end']),
            ];
        }

        return $bloques;
    }

    /**
     * Descarta los candidatos que chocan con un bloque ocupado.
     *
     * ## El buffer se aplica alrededor de lo ocupado, no solo entre turnos
     *
     * `GeneradorDeHorarios` ya separa los candidatos entre sí. Acá el buffer
     * cumple otra función: **despegar el turno nuevo de los eventos que ya
     * existen**. AC-05.2 lo pide explícito — con buffer de 10 y un turno que
     * termina 11:00, el primero ofrecido es 11:10 o posterior.
     *
     * Sin esto, el profesional saldría de un turno y entraría al siguiente sin
     * respirar, que es justo lo que el buffer existe para evitar. Y el bloque
     * ocupado puede ser un almuerzo o un viaje, no solo otro turno.
     *
     * @param  array<int,CarbonImmutable>  $candidatos
     * @param  array<int,array{0:CarbonImmutable,1:CarbonImmutable}>  $ocupados
     * @return array<int,CarbonImmutable>
     */
    private function restarOcupados(array $candidatos, array $ocupados, BusinessSetting $config): array
    {
        $duracion = $config->slot_duration_minutes;
        $buffer = $config->buffer_minutes;

        $libres = array_filter($candidatos, function (CarbonImmutable $inicio) use ($ocupados, $duracion, $buffer) {
            $fin = $inicio->addMinutes($duracion);

            foreach ($ocupados as [$ocupadoDesde, $ocupadoHasta]) {
                // La franja prohibida es el bloque ocupado más el buffer a cada
                // lado. Se compara por instante, así que las zonas horarias de
                // Google y del tenant no tienen que coincidir.
                $prohibidoDesde = $ocupadoDesde->subMinutes($buffer);
                $prohibidoHasta = $ocupadoHasta->addMinutes($buffer);

                // Solapamiento estricto: tocarse en el borde exacto no es choque.
                if ($inicio->lessThan($prohibidoHasta) && $fin->greaterThan($prohibidoDesde)) {
                    return false;
                }
            }

            return true;
        });

        return array_values($libres);
    }
}
