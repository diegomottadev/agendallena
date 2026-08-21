<?php

namespace App\Meta;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * T-050 · El paso de alta de una PyME nueva: sus tres plantillas, en **su**
 * cuenta de WhatsApp.
 *
 * ## Por qué la cuenta sale de la integración y nunca de la config
 *
 * `META_WABA_ID` es una variable de entorno global, o sea **la misma forma
 * exacta** del bug de aislamiento que se arregló el 2026-08-20 en `MetaAdapter`,
 * donde el número emisor salía de `config('services.meta.phone_number_id')` y el
 * cliente de una PyME recibía el mensaje desde el número de otra. Acá el daño es
 * el simétrico: las plantillas del cliente nuevo se crearían en la cuenta del
 * cliente viejo, y el nuevo seguiría sin poder mandar nada. Con un solo piloto
 * no se nota.
 *
 * ⚠️ Fuera de alcance: el *embedded signup* de Meta y la actualización o borrado
 * de plantillas de un cliente que ya está andando. Acá el trámite puede ser
 * parcialmente manual; lo que no puede es crear plantillas en la cuenta ajena.
 */
class AltaDeCuenta
{
    private const VERSION_API = 'v21.0';

    /** Lo que se registra cuando Meta rechazó la creación: ni pendiente ni aprobada. */
    public const RECHAZADA = 'REJECTED';

    /**
     * Crea las plantillas del tenant y devuelve el estado de cada una.
     *
     * `$soloEstas` existe para el alta que se vuelve a correr: una plantilla que
     * ya existe en la cuenta de Meta no se puede volver a pedir —contesta error
     * de nombre duplicado y quedaría registrada como rechazada—, así que quien
     * reintenta nombra las que faltan. Sin el parámetro, son las tres.
     *
     * @param  array<int,string>|null  $soloEstas  Nombres a crear; `null` es todas.
     * @return array<string,string>
     *
     * @throws SinCuentaDeWhatsApp  Si al tenant no le cargaron el `waba_id`.
     */
    public function crearPlantillas(Integration $meta, ?array $soloEstas = null): array
    {
        $wabaId = self::wabaIdDe($meta);

        $estados = [];

        foreach (PlantillasDeWhatsApp::todas() as $definicion) {
            if ($soloEstas !== null && ! in_array($definicion['name'], $soloEstas, true)) {
                continue;
            }

            $estados[$definicion['name']] = $this->crear($meta, $wabaId, $definicion);
        }

        return $estados;
    }

    /**
     * La cuenta de WhatsApp del tenant, o un error que dice qué falta.
     *
     * Se resuelve **antes** de cualquier petición: pegarle a Meta con la URL
     * incompleta devuelve un error de la plataforma que no menciona ni al tenant
     * ni al dato ausente.
     *
     * @throws SinCuentaDeWhatsApp
     */
    public static function wabaIdDe(Integration $meta): string
    {
        $settings = (array) ($meta->settings ?? []);
        $wabaId = trim((string) ($settings['waba_id'] ?? ''));

        if ($wabaId === '') {
            throw SinCuentaDeWhatsApp::paraElTenant((string) $meta->tenant_id);
        }

        return $wabaId;
    }

    /** ¿Este tenant tiene cargada su cuenta de WhatsApp? */
    public static function tieneCuenta(Integration $meta): bool
    {
        $settings = (array) ($meta->settings ?? []);

        return trim((string) ($settings['waba_id'] ?? '')) !== '';
    }

    /**
     * @param  array<string,mixed>  $definicion
     * @return string  El estado con el que quedó registrada.
     */
    private function crear(Integration $meta, string $wabaId, array $definicion): string
    {
        $respuesta = Http::withToken((string) $meta->access_token)
            ->timeout(30)
            ->post(
                'https://graph.facebook.com/'.self::VERSION_API.'/'.$wabaId.'/message_templates',
                $definicion,
            );

        if (! $respuesta->successful()) {
            /*
             * RNF-03 · Ningún camino termina en silencio. Un alta a medias —dos
             * plantillas creadas y una rechazada— es peor que una que falla
             * entera: el cliente parece dado de alta y le faltan mensajes. Queda
             * registrada la plantilla y el código de error de Meta, nunca el token.
             */
            Log::error('Meta rechazó la creación de una plantilla del tenant', [
                'tenant_id' => $meta->tenant_id,
                'integracion' => 'meta_whatsapp',
                'codigo' => 'ALTA_PLANTILLA_RECHAZADA',
                'plantilla' => $definicion['name'],
                'http' => $respuesta->status(),
                'error_meta' => $respuesta->json('error.code'),
            ]);

            PlantillasDelTenant::registrar($meta, $definicion['name'], self::RECHAZADA);

            return self::RECHAZADA;
        }

        // Meta contesta `PENDING` casi siempre: la aprobación es asincrónica y su
        // demora no la controlamos. Lo que se guarda es lo que contestó, no un
        // optimismo nuestro.
        $estado = strtoupper(trim((string) $respuesta->json('status')));
        $estado = $estado !== '' ? $estado : 'PENDING';

        PlantillasDelTenant::registrar($meta, $definicion['name'], $estado);

        return $estado;
    }
}
