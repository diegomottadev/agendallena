<?php

namespace App\Meta;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * T-050 · Ir a preguntarle a Meta en qué estado están **las plantillas de esta
 * PyME**, y registrar lo que conteste.
 *
 * ## Por qué hay que ir a preguntar
 *
 * `PENDING` es el estado normal de todo cliente recién dado de alta: Meta
 * aprueba de forma asincrónica y su demora no la controlamos. Pero **no existe
 * handler del webhook `message_template_status_update`**, así que nada le avisa
 * al sistema cuando la aprobación sale. Sin esta consulta, una plantilla
 * registrada `PENDING` no puede llegar nunca a `APPROVED`, y como
 * `EnviarRecordatorios` exige `todasAprobadas()` esa PyME **no manda un solo
 * recordatorio, nunca**, sin ningún error que lo avise.
 *
 * ⚠️ Preguntar es el parche, no la solución: el webhook sigue faltando y es el
 * único camino por el que el sistema se entera *en el momento*.
 *
 * ## Solo lee
 *
 * Nunca hace un `POST`. Volver a pedir una plantilla que ya existe en la cuenta
 * devuelve error de nombre duplicado y la dejaría registrada como `REJECTED`, o
 * sea que una consulta que se creyera con derecho a crear rompería justo al
 * cliente que venía a destrabar. Quién crea es `AltaDeCuenta`, y solo cuando un
 * operador lo pide.
 *
 * ## La cuenta sale de la integración del tenant
 *
 * ⚠️ `config('services.meta.waba_id')` es una variable de entorno global: con
 * ella, la PyME nueva quedaría marcada con el estado de las plantillas del
 * cliente viejo. Si lo copia como `APPROVED`, el recordatorio muere en
 * `template not found`; si lo copia como `PENDING`, queda trabada por un motivo
 * que no es suyo. Es la misma forma del bug del emisor que ya apareció dos veces
 * en este proyecto (RNF-01).
 */
class EstadoDePlantillasEnMeta
{
    private const VERSION_API = 'v21.0';

    /**
     * Sincroniza el estado de las tres plantillas del tenant contra su cuenta.
     *
     * @return array<string,string>  El estado registrado de cada plantilla, tras sincronizar.
     *
     * @throws SinCuentaDeWhatsApp  Si al tenant no le cargaron el `waba_id`.
     * @throws RuntimeException  Si Meta no contestó. Quien llama decide si eso
     *                           frena su corrida o solo a esta PyME.
     */
    public function sincronizar(Integration $meta): array
    {
        $wabaId = AltaDeCuenta::wabaIdDe($meta);

        $respuesta = Http::withToken((string) $meta->access_token)
            ->timeout(30)
            ->get(
                'https://graph.facebook.com/'.self::VERSION_API.'/'.$wabaId.'/message_templates',
                // Se piden los dos campos que deciden el envío y nada más: el
                // cuerpo completo de las tres plantillas es payload que no se
                // mira, en una llamada que corre por cada PyME en cada corrida.
                ['fields' => 'name,status', 'limit' => 100],
            );

        if (! $respuesta->successful()) {
            /*
             * No se registra nada. Un error de Meta —token vencido, plataforma
             * caída— **no autoriza a concluir nada** sobre las plantillas del
             * cliente: dejarlas en el estado que ya tenían es lo único honesto.
             *
             * ⚠️ El mensaje nombra al tenant y al código HTTP, nunca al
             * `access_token` ni a un fragmento suyo.
             */
            throw new RuntimeException(
                "Meta no contestó el estado de las plantillas del tenant {$meta->tenant_id} "
                ."(HTTP {$respuesta->status()})."
            );
        }

        foreach ((array) $respuesta->json('data', []) as $plantilla) {
            $nombre = (string) (((array) $plantilla)['name'] ?? '');
            $estado = strtoupper(trim((string) (((array) $plantilla)['status'] ?? '')));

            /*
             * La cuenta de la PyME puede tener plantillas que no son nuestras
             * —el dueño creó las suyas, o quedaron de otra herramienta—. Se
             * registran solo las tres que el producto usa: cualquier otra
             * ensuciaría el estado que decide el envío.
             */
            if ($estado === '' || ! in_array($nombre, PlantillasDeWhatsApp::NOMBRES, true)) {
                continue;
            }

            PlantillasDelTenant::registrar($meta, $nombre, $estado);
        }

        return PlantillasDelTenant::estados($meta);
    }
}
