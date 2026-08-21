<?php

namespace App\Conversacion;

use App\Conversacion\Interactivo\Opcion;
use App\Conversacion\Interactivo\RenderizadorInteractivo;
use App\Models\Conversation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * T-023 · Los horarios libres, como lista interactiva de WhatsApp.
 *
 * ## El `id` lleva el instante completo, no la hora
 *
 * Es la nota de riesgo del ticket, y el modo de falla es concreto: con `slot_0900`
 * el martes a las 9 y el miércoles a las 9 producen **el mismo `id`**. El cliente
 * elige el miércoles y se le agenda el martes — y nadie se entera hasta que no
 * aparece.
 *
 * Va como **timestamp Unix**: son diez caracteres contra los veinte de un ISO,
 * y el `id` de Meta tiene tope. Es UTC por definición, así que tampoco arrastra
 * la ambigüedad de una hora local sin huso.
 *
 * ## Los horarios se muestran en la zona del tenant
 *
 * El instante viaja en UTC dentro del `id` y se **muestra** convertido (RNF-02).
 * El día va explícito en la descripción: una lista de diez horarios sin fecha
 * obliga al cliente a adivinar cuáles son de hoy y cuáles de mañana.
 */
class ListaDeHorarios
{
    private const PREFIJO = 'slot_';

    public function __construct(private readonly RenderizadorInteractivo $render) {}

    /**
     * @param  array<int,CarbonImmutable>  $horarios  Los que devolvió T-022.
     * @param  int  $pagina  Base 0.
     * @return array<string,mixed>|null  `null` si esa página no existe.
     */
    public function mensaje(
        Conversation $conversacion,
        Tenant $tenant,
        array $horarios,
        int $pagina = 0,
    ): ?array {
        $paginas = $this->render->paginar(
            $this->comoOpciones($horarios, $tenant),
            self::PREFIJO.'pagina',
        );

        if (! isset($paginas[$pagina])) {
            return null;
        }

        return $this->render->lista(
            $conversacion,
            $this->cuerpo($pagina, count($paginas)),
            'Ver horarios',
            $paginas[$pagina],
            $this->tituloDeSeccion($horarios, $tenant),
        );
    }

    /**
     * Convierte los instantes en opciones legibles.
     *
     * @param  array<int,CarbonImmutable>  $horarios
     * @return array<int,Opcion>
     */
    private function comoOpciones(array $horarios, Tenant $tenant): array
    {
        return array_map(function (CarbonImmutable $h) use ($tenant) {
            $local = $h->setTimezone($tenant->timezone);

            return new Opcion(
                accion: self::PREFIJO.$h->getTimestamp(),
                titulo: $local->format('H:i').' hs',
                // El día explícito: sin esto, diez horarios seguidos son
                // indistinguibles entre hoy y pasado mañana.
                descripcion: $local->locale('es')->isoFormat('dddd D [de] MMMM'),
            );
        }, array_values($horarios));
    }

    /**
     * Lee el instante que eligió el cliente.
     *
     * Devuelve `null` si la acción no es un horario: puede ser «ver más», o un
     * botón de otro paso que el sello ya dejó pasar por vigente.
     */
    public static function horarioDe(?string $accion): ?CarbonImmutable
    {
        if (! is_string($accion) || ! str_starts_with($accion, self::PREFIJO)) {
            return null;
        }

        $resto = substr($accion, strlen(self::PREFIJO));

        if (! ctype_digit($resto)) {
            return null;   // `slot_pagina_1` y similares.
        }

        return CarbonImmutable::createFromTimestamp((int) $resto, 'UTC');
    }

    /** ¿La acción es un pedido de la página siguiente? */
    public static function paginaSolicitada(?string $accion): ?int
    {
        if (! is_string($accion) || ! str_starts_with($accion, self::PREFIJO.'pagina_')) {
            return null;
        }

        $numero = substr($accion, strlen(self::PREFIJO.'pagina_'));

        return ctype_digit($numero) ? (int) $numero : null;
    }

    private function cuerpo(int $pagina, int $total): string
    {
        if ($total <= 1) {
            return 'Estos son los horarios que tengo disponibles. Elegí el que te quede mejor:';
        }

        return $pagina === 0
            ? 'Estos son los primeros horarios disponibles. Si ninguno te sirve, podés ver más:'
            : 'Más horarios disponibles:';
    }

    /**
     * Título de la sección.
     *
     * Cuando toda la lista cae en un mismo día, se pone la fecha; si abarca
     * varios, un título genérico — poner el del primero sería mentir sobre los
     * de abajo.
     *
     * @param  array<int,CarbonImmutable>  $horarios
     */
    private function tituloDeSeccion(array $horarios, Tenant $tenant): string
    {
        if ($horarios === []) {
            return 'Horarios';
        }

        $dias = array_unique(array_map(
            fn (CarbonImmutable $h) => $h->setTimezone($tenant->timezone)->toDateString(),
            $horarios
        ));

        if (count($dias) === 1) {
            return CarbonImmutable::parse(reset($dias))->locale('es')->isoFormat('dddd D [de] MMM');
        }

        return 'Horarios disponibles';
    }
}
