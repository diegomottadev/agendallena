<?php

namespace App\Services\Agenda;

use App\Models\BusinessSetting;
use Carbon\CarbonImmutable;

/**
 * T-015 · Grilla de horarios candidatos según la configuración del negocio.
 *
 * Produce **los horarios que el negocio podría ofrecer**, sin saber nada de
 * Google: T-022 los cruza contra `freeBusy` para quedarse con los realmente
 * libres. Esta separación es lo que hace verificable a AC-15.2 antes de que
 * exista la consulta de disponibilidad.
 *
 * ## La aritmética de AC-15.2
 *
 * El paso entre turnos es **duración + buffer**, no duración a secas. Con
 * turnos de 45 minutos y buffer de 10, desde las 09:00 salen 09:00, 09:55,
 * 10:50 — y no 09:00, 09:45, 10:30. El buffer existe para que el profesional
 * respire entre clientes; si el paso fuera solo la duración, el buffer no
 * tendría efecto y el criterio se leería como cumplido estando mal.
 *
 * ## Zonas horarias (RNF-02)
 *
 * `business_hours` guarda horas **locales del tenant** —"abrimos a las 9" es
 * una regla, no un instante— y esta clase devuelve `CarbonImmutable` en esa
 * zona. La conversión a UTC ocurre al persistir, no acá.
 */
class GeneradorDeHorarios
{
    public function __construct(private readonly BusinessSetting $config) {}

    /**
     * Horarios candidatos de un día, en la zona del tenant.
     *
     * @return array<int,CarbonImmutable>
     */
    public function paraElDia(CarbonImmutable $dia, string $timezone): array
    {
        $dia = $this->diaDelNegocio($dia, $timezone);
        $clave = $this->claveDelDia($dia);

        $paso = $this->config->slot_duration_minutes + $this->config->buffer_minutes;
        $duracion = $this->config->slot_duration_minutes;

        $horarios = [];

        foreach ($this->config->rangosDe($clave) as [$desde, $hasta]) {
            $inicio = $this->aHora($dia, $desde);
            $cierre = $this->aHora($dia, $hasta);

            for ($t = $inicio; ; $t = $t->addMinutes($paso)) {
                /*
                 * El turno tiene que **terminar** antes del cierre, no empezar.
                 * Ofrecer las 17:45 con turnos de 45 minutos y cierre a las 18:00
                 * agenda a alguien hasta las 18:30: el cliente llega puntual y el
                 * local está cerrando.
                 */
                if ($t->addMinutes($duracion)->greaterThan($cierre)) {
                    break;
                }

                $horarios[] = $t;
            }
        }

        return $horarios;
    }

    /** ¿El negocio atiende ese día? (AC-15.1) */
    public function atiendeEl(CarbonImmutable $dia, string $timezone): bool
    {
        return $this->config->abreEl($this->claveDelDia($this->diaDelNegocio($dia, $timezone)));
    }

    /**
     * El día calendario **tal como lo nombra el negocio**, no el instante convertido.
     *
     * La diferencia importa y es un off-by-one esperando a pasar: convertir
     * `2026-08-24 00:00 UTC` a `America/Bogota` (UTC−5) da el **domingo 23**. Un
     * llamador que pide "los horarios del lunes 24" recibiría los del domingo,
     * que además está cerrado — y el síntoma sería "el bot no ofrece turnos los
     * domingos a la noche", diagnosticable sólo mirando husos.
     *
     * Se toma la fecha calendario del argumento y se construye la medianoche en
     * la zona del tenant. "Lunes 24" significa lunes 24 para el negocio,
     * independientemente de en qué huso venía escrito.
     */
    private function diaDelNegocio(CarbonImmutable $dia, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $dia->format('Y-m-d').' 00:00:00', $timezone);
    }

    /**
     * Días de atención en texto, para decírselo al cliente cuando pide un día
     * cerrado — AC-15.1 exige indicarlos, no solo negar el turno.
     */
    public function diasDeAtencionEnTexto(): string
    {
        $nombres = [
            'mon' => 'lunes', 'tue' => 'martes', 'wed' => 'miércoles', 'thu' => 'jueves',
            'fri' => 'viernes', 'sat' => 'sábados', 'sun' => 'domingos',
        ];

        $abiertos = array_keys(array_filter(
            $nombres,
            fn (string $clave) => $this->config->abreEl($clave),
            ARRAY_FILTER_USE_KEY
        ));

        if ($abiertos === []) {
            return 'ningún día';
        }

        // Se preserva el orden de la semana, no el del array de configuración.
        $ordenados = array_values(array_intersect(array_keys($nombres), $abiertos));
        $textos = array_map(fn (string $c) => $nombres[$c], $ordenados);

        if (count($textos) === 1) {
            return $textos[0];
        }

        $ultimo = array_pop($textos);

        return implode(', ', $textos).' y '.$ultimo;
    }

    private function claveDelDia(CarbonImmutable $dia): string
    {
        return strtolower($dia->format('D'));   // mon, tue, ...
    }

    private function aHora(CarbonImmutable $dia, string $hhmm): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $dia->setTime($h, $m);
    }
}
