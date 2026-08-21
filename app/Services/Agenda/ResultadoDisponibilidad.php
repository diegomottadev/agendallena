<?php

namespace App\Services\Agenda;

use Carbon\CarbonImmutable;

/**
 * T-024 · El resultado de consultar disponibilidad, **con su causa**.
 *
 * ## Por qué no alcanza con devolver un array
 *
 * Antes de este ticket, `horariosLibres()` devolvía `[]` en tres situaciones que
 * no tienen nada que ver entre sí:
 *
 * | Situación | Qué es realmente | Qué hay que contestarle al cliente |
 * | :-- | :-- | :-- |
 * | Todo ocupado | La agenda está llena | "No tengo lugar, ¿querés que te contacte alguien?" |
 * | Sin días configurados | **Un bug nuestro** | El mensaje de fallback, y alguien tiene que enterarse |
 * | Google no respondió | Falla de un tercero | El mensaje de cortesía de RNF-03 |
 *
 * AC-19.4 lo dice sin ambigüedad y **el recorte Pareto no lo toca**: comunicar
 * un bug nuestro como *"no hay lugar"* es mentirle al cliente de la PyME. Peor
 * todavía, le miente en la dirección que hace perder la venta — el cliente se
 * va a buscar turno a otro lado por un problema de configuración que nadie vio.
 *
 * Un `array` vacío no puede llevar esa distinción. Un tipo, sí.
 */
class ResultadoDisponibilidad
{
    /**
     * @param  array<int,CarbonImmutable>  $horarios
     */
    private function __construct(
        public readonly Motivo $motivo,
        public readonly array $horarios = [],
        public readonly int $diasConsultados = 0,
    ) {}

    /**
     * @param  array<int,CarbonImmutable>  $horarios
     */
    public static function conHorarios(array $horarios, int $dias): self
    {
        return new self(Motivo::HayHorarios, $horarios, $dias);
    }

    /** Había horarios candidatos, pero todos están ocupados. */
    public static function agendaLlena(int $dias): self
    {
        return new self(Motivo::AgendaLlena, [], $dias);
    }

    /**
     * El negocio no tiene ni un día de atención configurado.
     *
     * No es "está lleno": es que nadie cargó el horario. Se distingue para que
     * el cliente no reciba un "no hay lugar" falso y para que quede registro de
     * que hay algo que arreglar.
     */
    public static function sinConfiguracion(): self
    {
        return new self(Motivo::SinConfiguracion);
    }

    public function hayHorarios(): bool
    {
        return $this->motivo === Motivo::HayHorarios;
    }

    /** ¿El vacío es culpa nuestra y no de la agenda del cliente? */
    public function esProblemaNuestro(): bool
    {
        return $this->motivo === Motivo::SinConfiguracion;
    }
}
