<?php

namespace App\Conversacion\Interactivo;

/**
 * Una opción que el cliente puede tocar: un botón o una fila de la lista.
 *
 * La `accion` es lo que el bot entiende; el `titulo` es lo que el cliente lee.
 * Separarlos permite cambiar el copy sin tocar la lógica — y que el mismo
 * `slot_3` signifique lo mismo diga «09:30» o «9 y media».
 */
class Opcion
{
    public function __construct(
        public readonly string $accion,
        public readonly string $titulo,
        public readonly ?string $descripcion = null,
    ) {}
}
