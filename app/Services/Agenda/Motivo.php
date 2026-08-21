<?php

namespace App\Services\Agenda;

/**
 * Por qué la consulta de disponibilidad devolvió lo que devolvió.
 *
 * El error de la API **no está acá**: viaja como excepción
 * (`DisponibilidadNoDisponible`), porque no es un resultado sino una
 * interrupción — no llegamos a saber qué hay libre.
 */
enum Motivo: string
{
    case HayHorarios = 'hay_horarios';

    /** Todo ocupado en la ventana consultada. Es la agenda del cliente, no un bug. */
    case AgendaLlena = 'agenda_llena';

    /** Ningún día de atención configurado. **Es un bug nuestro.** */
    case SinConfiguracion = 'sin_configuracion';
}
