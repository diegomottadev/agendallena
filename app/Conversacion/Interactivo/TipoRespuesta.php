<?php

namespace App\Conversacion\Interactivo;

enum TipoRespuesta: string
{
    /** Botón del paso actual: se ejecuta. */
    case Vigente = 'vigente';

    /** Botón de un mensaje anterior (AC-03.2): NO se ejecuta. */
    case Caduca = 'caduca';

    /** Id con forma de botón que este código no emitió. */
    case Desconocida = 'desconocida';

    /** Texto libre, audio o imagen. */
    case NoInteractiva = 'no_interactiva';
}
