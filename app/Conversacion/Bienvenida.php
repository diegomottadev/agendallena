<?php

namespace App\Conversacion;

use App\Conversacion\Interactivo\Opcion;
use App\Conversacion\Interactivo\RenderizadorInteractivo;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Tenant;

/**
 * T-021 · El mensaje de bienvenida y su menú.
 *
 * Es **el único mensaje que recibe el 100 % de los clientes finales**, y hasta el
 * story map no estaba definido en ningún lado: US-03 declaraba el estado `IDLE` y
 * nadie había especificado qué se contesta en él.
 */
class Bienvenida
{
    /** AC-16.3 · Lo que el cliente escribe para volver al principio. */
    private const PALABRAS_DE_REINICIO = ['menu', 'menú', 'inicio', 'empezar', 'reiniciar', 'volver'];

    public function __construct(private readonly RenderizadorInteractivo $render) {}

    /**
     * El mensaje interactivo de bienvenida.
     *
     * @return array<string,mixed>
     */
    public function mensaje(Conversation $conversacion, Tenant $tenant, BusinessSetting $config): array
    {
        return $this->render->botones(
            $conversacion,
            $this->texto($tenant, $config),
            $this->opciones(),
        );
    }

    /**
     * El texto sale de la configuración del tenant (T-017), con el nombre del
     * negocio interpolado.
     *
     * AC-16.1 pide *"un saludo con el nombre del negocio"*: sin eso, el cliente
     * que le escribe a tres peluquerías recibe tres mensajes idénticos y no sabe
     * a cuál le está hablando.
     */
    private function texto(Tenant $tenant, BusinessSetting $config): string
    {
        $texto = trim((string) $config->welcome_message);

        if ($texto === '') {
            $texto = BusinessSetting::valoresPorDefecto()['welcome_message'];
        }

        /*
         * Se antepone el nombre en vez de exigir una variable en el texto. El
         * recorte Pareto de T-017 difiere el sistema de variables —*"flexibilidad
         * especulativa con un solo caller"*—, así que el nombre se interpola acá
         * y el dueño no tiene que acordarse de escribir `{{negocio}}`.
         */
        if (! str_contains($texto, $tenant->name)) {
            $texto = "*{$tenant->name}*\n\n".$texto;
        }

        return $texto;
    }

    /**
     * Las opciones del menú.
     *
     * ⚠️ **El contenido concreto está fuera del alcance de T-021** —*"depende de
     * qué flujos existan al momento de implementarlo"*—. Hoy existe uno solo:
     * reservar. Cotizar cae con el cotizador diferido, y hablar con una persona
     * es T-035.
     *
     * Se deja **una sola opción a propósito** en vez de inventar botones que no
     * llevan a ninguna parte: un menú con opciones muertas es peor que un menú
     * corto.
     *
     * @return array<int,Opcion>
     */
    private function opciones(): array
    {
        return [
            new Opcion('reservar', 'Reservar un turno'),
        ];
    }

    /**
     * AC-16.3 · ¿El cliente pidió volver al principio?
     *
     * Se compara sobre el texto normalizado —sin acentos, sin mayúsculas, sin
     * signos— porque quien escribe «MENÚ!» desde el celular está pidiendo
     * exactamente lo mismo que quien escribe «menu».
     */
    public static function esPalabraDeReinicio(?string $texto): bool
    {
        if (! is_string($texto)) {
            return false;
        }

        $normalizado = self::normalizar($texto);

        return in_array($normalizado, array_map(self::normalizar(...), self::PALABRAS_DE_REINICIO), true);
    }

    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $sinAcentos = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $sinAcentos) ?? '';
    }
}
