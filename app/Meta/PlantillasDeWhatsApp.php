<?php

namespace App\Meta;

/**
 * T-050 · Las tres plantillas que Meta aprobó el 2026-08-19, como payload de
 * `POST /{waba-id}/message_templates`.
 *
 * ## Por qué esto es contractual y no configuración
 *
 * Los cuatro `{{n}}` son **posicionales**: uno de más, uno de menos o en otro
 * orden hace que Meta **rechace el mensaje entero**, y ese error no se ve al
 * crear la plantilla sino recién en producción, cuando el recordatorio no llega.
 * Lo mismo con el idioma: con un `language` distinto al que usa el envío, Meta
 * contesta `template not found` y el error no dice cuál de los dos está mal.
 *
 * El orden de los botones también quedó congelado con la aprobación:
 * `PlantillaRecordatorio24h::BOTONES` mapea la acción **por índice**, así que
 * crearlos en otro orden en la cuenta del cliente haría que *Confirmar* cancele
 * turnos sin ningún error que lo avise.
 *
 * Los cuerpos salen de `.claude/docs/plan-for-diego/plantillas-meta.md`.
 */
class PlantillasDeWhatsApp
{
    public const RECORDATORIO_24H = 'recordatorio_turno_24h';

    public const CONFIRMACION_RESERVA = 'confirmacion_reserva';

    public const AVISO_2H = 'aviso_turno_2h';

    /** El idioma lo fija la aprobación, no una preferencia de estilo. */
    public const IDIOMA = 'es_AR';

    /**
     * Utility y no Marketing: es más barata y no exige el opt-in de marketing.
     * Una plantilla recategorizada cambia las dos cosas de golpe.
     */
    public const CATEGORIA = 'UTILITY';

    /** @var array<int,string> */
    public const NOMBRES = [
        self::RECORDATORIO_24H,
        self::CONFIRMACION_RESERVA,
        self::AVISO_2H,
    ];

    /**
     * El texto de los tres botones y **que son tres**. El `payload` de cada uno
     * no va acá: se fija en cada envío (ver `PlantillaRecordatorio24h`).
     *
     * @var array<int,string>
     */
    public const BOTONES = ['Confirmar', 'Re-agendar', 'Cancelar'];

    /**
     * Meta exige ejemplos para las variables y **rechaza los poco plausibles**
     * (`xxx`, `aaa`). Son los mismos que se cargaron a mano el 2026-08-19.
     *
     * @var array<int,string>
     */
    private const EJEMPLOS = ['María', 'Peluquería Sur', 'Corte y color', 'martes 26 de agosto a las 15:30'];

    /** Igual que arriba, pero la de t-2h muestra solo la hora en `{{4}}`. */
    private const EJEMPLOS_2H = ['María', 'Peluquería Sur', 'Corte y color', '15:30'];

    /**
     * @return array<int,array<string,mixed>>  Una entrada por plantilla, lista para postear.
     */
    public static function todas(): array
    {
        return [
            self::armar(
                self::RECORDATORIO_24H,
                "Hola {{1}}, te recordamos tu turno en {{2}}.\n\nServicio: {{3}}\nCuándo: {{4}}\n\n¿Nos confirmás que venís?",
                self::EJEMPLOS,
                self::BOTONES,
            ),
            self::armar(
                self::CONFIRMACION_RESERVA,
                "Hola {{1}}, tu turno en {{2}} quedó reservado.\n\nServicio: {{3}}\nCuándo: {{4}}\n\nTe vamos a escribir un día antes para recordártelo.",
                self::EJEMPLOS,
            ),
            self::armar(
                self::AVISO_2H,
                "Hola {{1}}, te esperamos hoy en {{2}}.\n\nServicio: {{3}}\nHorario: {{4}}\n\n¡Nos vemos!",
                self::EJEMPLOS_2H,
            ),
        ];
    }

    /**
     * @param  array<int,string>  $ejemplos
     * @param  array<int,string>  $botones
     * @return array<string,mixed>
     */
    private static function armar(string $nombre, string $cuerpo, array $ejemplos, array $botones = []): array
    {
        $componentes = [[
            'type' => 'BODY',
            'text' => $cuerpo,
            'example' => ['body_text' => [$ejemplos]],
        ]];

        if ($botones !== []) {
            $componentes[] = [
                'type' => 'BUTTONS',
                'buttons' => array_map(
                    static fn (string $texto): array => ['type' => 'QUICK_REPLY', 'text' => $texto],
                    $botones,
                ),
            ];
        }

        return [
            'name' => $nombre,
            'language' => self::IDIOMA,
            'category' => self::CATEGORIA,
            'components' => $componentes,
        ];
    }
}
