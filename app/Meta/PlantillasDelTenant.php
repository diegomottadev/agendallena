<?php

namespace App\Meta;

use App\Models\Integration;

/**
 * T-050 · El estado de aprobación de las plantillas **de cada PyME**.
 *
 * Con una WABA por cliente, las plantillas se aprueban **por cuenta**: las tres
 * que aprobó Meta el 2026-08-19 sirven para la cuenta de prueba y para ninguna
 * otra. O sea que "¿está aprobada?" no tiene una respuesta global, y guardarla
 * en una variable de entorno haría que el primer cliente listo destrabe los
 * envíos de todos los demás.
 *
 * Vive en `integrations.settings`, el mismo JSON encriptado donde ya vive el
 * `verify_token` de cada tenant: no hace falta tabla ni migración.
 *
 * ⚠️ Se relee de la base y no del modelo que llega por parámetro: el alta corre
 * fuera de un request y quien consulta después suele tener en la mano una
 * instancia cargada antes de que el alta escribiera.
 */
class PlantillasDelTenant
{
    /** Clave dentro de `integrations.settings`. */
    public const CLAVE = 'plantillas';

    public const APROBADA = 'APPROVED';

    /**
     * El estado de cada plantilla del tenant, por nombre.
     *
     * @return array<string,string>
     */
    public static function estados(Integration $meta): array
    {
        $settings = (array) (self::recargar($meta)->settings ?? []);

        return array_map(
            static fn ($estado): string => (string) $estado,
            (array) ($settings[self::CLAVE] ?? []),
        );
    }

    /**
     * ¿Este tenant ya puede mandar plantillas?
     *
     * Falso mientras falte alguna de las tres o alguna siga esperando a Meta. El
     * tiempo de aprobación no lo controlamos, así que este estado dura lo que
     * dure.
     */
    public static function todasAprobadas(Integration $meta): bool
    {
        $estados = self::estados($meta);

        if ($estados === []) {
            return false;
        }

        foreach (PlantillasDeWhatsApp::NOMBRES as $nombre) {
            if (strtoupper($estados[$nombre] ?? '') !== self::APROBADA) {
                return false;
            }
        }

        return true;
    }

    /**
     * Las plantillas de este tenant que **todavía no se pueden usar**, para que
     * quien va a enviar sepa qué nombrar en el registro.
     *
     * Se recorre el juego completo de `NOMBRES` y no lo registrado, así que
     * **la que nunca se creó también aparece**. Antes se recorría lo registrado,
     * y entonces la PyME sin alta hecha devolvía una lista vacía — la misma
     * forma que "están todas aprobadas". Dos métodos de esta clase contestaban
     * distinto sobre el mismo estado y el que decidía el envío era el laxo.
     *
     * Ahora `pendientes() === []` y `todasAprobadas()` responden lo mismo.
     *
     * @return array<int,string>
     */
    public static function pendientes(Integration $meta): array
    {
        $estados = self::estados($meta);

        $pendientes = [];

        foreach (PlantillasDeWhatsApp::NOMBRES as $nombre) {
            if (strtoupper((string) ($estados[$nombre] ?? '')) !== self::APROBADA) {
                $pendientes[] = $nombre;
            }
        }

        return $pendientes;
    }

    /** Deja registrado lo que Meta contestó al crear una plantilla del tenant. */
    public static function registrar(Integration $meta, string $nombre, string $estado): void
    {
        $settings = (array) (self::recargar($meta)->settings ?? []);
        $plantillas = (array) ($settings[self::CLAVE] ?? []);

        $plantillas[$nombre] = $estado;
        $settings[self::CLAVE] = $plantillas;

        $meta->forceFill(['settings' => $settings])->save();
    }

    /**
     * ⚠️ `Integration` es la única tabla de negocio **sin** Global Scope de
     * tenant —la ingesta resuelve el tenant a partir del `phone_number_id`—, así
     * que esto se puede consultar sin contexto activo. El aislamiento lo da la
     * llave primaria, que ya identifica a una sola PyME.
     */
    private static function recargar(Integration $meta): Integration
    {
        return Integration::query()->find($meta->getKey()) ?? $meta;
    }
}
