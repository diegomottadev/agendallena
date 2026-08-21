<?php

namespace App\Conversacion;

use App\Models\Conversation;
use RuntimeException;

/**
 * El evento no corresponde al estado actual.
 *
 * **No es un error del sistema: es lo normal.** El cliente escribe cualquier
 * cosa en cualquier momento, y la mitad de las veces no corresponde al paso en
 * el que está. AC-03.4 dice qué hacer: el bot **recuerda qué se espera y no
 * cambia de estado**.
 *
 * Por eso la excepción lleva los eventos que sí eran válidos: quien la atrapa
 * puede decirle al cliente qué se esperaba de él, en vez de un "no entendí".
 */
class TransicionNoDeclarada extends RuntimeException
{
    /** @var array<int,Transicion> */
    public readonly array $esperados;

    public function __construct(
        public readonly Conversation $conversacion,
        public readonly Estado $estadoActual,
        public readonly Transicion $eventoRecibido,
    ) {
        $this->esperados = TablaDeTransiciones::eventosValidosDesde($estadoActual);

        $lista = implode(', ', array_map(fn (Transicion $t) => $t->value, $this->esperados)) ?: 'ninguno';

        parent::__construct(
            "El evento '{$eventoRecibido->value}' no está declarado desde el estado "
            ."'{$estadoActual->value}'. Se esperaba alguno de: {$lista}."
        );
    }
}
