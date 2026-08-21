<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast que **garantiza** UTC en la base (RNF-02).
 *
 * ## El bug que evita
 *
 * El cast `datetime` de Laravel formatea el `Carbon` **en la zona que traiga**,
 * sin normalizar. Guardar un `Carbon::parse('18:00', 'America/Argentina/Buenos_Aires')`
 * escribe `18:00` en la columna, no `21:00`. No hay error, no hay warning: la
 * fila queda tres horas corrida y el sistema sigue andando.
 *
 * Es la definición exacta de la invariante que este proyecto declara que se
 * viola en silencio. Confiar en que cada `->save()` pase el instante ya
 * convertido es confiar en la disciplina de todos, siempre.
 *
 * ## Qué hace
 *
 * - **Al escribir:** convierte a UTC venga en la zona que venga.
 * - **Al leer:** devuelve el instante en UTC. La conversión a la hora del
 *   tenant es responsabilidad de `HoraLocal`, en el borde, y solo para mostrar.
 */
class FechaUtc implements CastsAttributes
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        // Lo que hay en la columna es UTC por definición: se interpreta así y no
        // según la zona del proceso, que podría cambiar.
        return CarbonImmutable::parse($value, 'UTC');
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        /*
         * Una cadena sin zona se interpreta en UTC. Es lo menos sorprendente:
         * quien escribe '2026-08-19 21:00' a mano en este proyecto está pensando
         * en la columna, y la columna es UTC. Para pasar hora local hay que
         * construir un Carbon con su zona, que es explícito y no se confunde.
         */
        $instante = $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value, 'UTC');

        return $instante->utc()->format('Y-m-d H:i:s');
    }
}
