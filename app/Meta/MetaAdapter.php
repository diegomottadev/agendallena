<?php

namespace App\Meta;

use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envío de mensajes a través de la Cloud API de Meta.
 *
 * Es el primer código del proyecto que **le habla al cliente final**. Todo lo
 * anterior recibía, guardaba o decidía.
 */
class MetaAdapter
{
    private const VERSION_API = 'v21.0';

    public function __construct(private readonly Integration $integration) {}

    /**
     * Manda un mensaje interactivo y lo persiste en el historial.
     *
     * @param  array<string,mixed>  $interactivo  Lo que arma `RenderizadorInteractivo`.
     */
    public function enviarInteractivo(Conversation $conversacion, array $interactivo, ?float $recibidoEn = null): ?string
    {
        return $this->enviar($conversacion, [
            'type' => 'interactive',
            'interactive' => $interactivo,
        ], $this->textoLegibleDe($interactivo), $recibidoEn);
    }

    /**
     * T-037 · Manda una plantilla aprobada.
     *
     * **Es el único envío que puede salir fuera de la ventana de 24 h de Meta**,
     * y por eso el recordatorio t-24h no puede ser texto plano: cuando el turno
     * se agendó hace tres días, un `enviarTexto` lo rechazaría Meta y el cliente
     * no recibiría nada.
     *
     * @param  array<string,mixed>  $plantilla  Lo que arma `PlantillaRecordatorio24h`.
     * @param  string  $textoParaElHistorial  La plantilla no se lee sola en el panel.
     */
    public function enviarPlantilla(Conversation $conversacion, array $plantilla, string $textoParaElHistorial, ?float $recibidoEn = null): ?string
    {
        return $this->enviar($conversacion, [
            'type' => 'template',
            'template' => $plantilla,
        ], $textoParaElHistorial, $recibidoEn);
    }

    /** Manda texto plano. Solo válido dentro de la ventana de 24 h de Meta. */
    public function enviarTexto(Conversation $conversacion, string $texto, ?float $recibidoEn = null): ?string
    {
        return $this->enviar($conversacion, [
            'type' => 'text',
            'text' => ['body' => $texto, 'preview_url' => false],
        ], $texto, $recibidoEn);
    }

    /**
     * @param  array<string,mixed>  $cuerpo
     * @param  float|null  $recibidoEn  `microtime(true)` de cuando entró el webhook.
     * @return string|null  El `wamid`, o `null` si Meta rechazó el envío.
     */
    private function enviar(Conversation $conversacion, array $cuerpo, string $textoParaElHistorial, ?float $recibidoEn): ?string
    {
        /*
         * El destinatario se normaliza acá y no antes: `conversations.user_phone`
         * guarda el `wa_id` que manda Meta —con el 9 en Argentina— y ese formato
         * **no se puede usar para enviar**. Ver `NumeroDeWhatsApp`.
         */
        $destinatario = NumeroDeWhatsApp::paraEnviar($conversacion->user_phone);

        /*
         * El emisor sale de la integración del tenant y **nunca de una variable
         * de entorno global**: `config('services.meta.phone_number_id')` es un
         * único número para todos, así que la respuesta a un cliente de la
         * peluquería saldría desde el número del consultorio. `account_identifier`
         * es la misma columna con la que `ProcessMessageJob` mapea el webhook
         * entrante a su tenant, así que la entrada y la salida usan el mismo número.
         */
        $url = 'https://graph.facebook.com/'.self::VERSION_API
            .'/'.$this->integration->account_identifier.'/messages';

        $respuesta = Http::withToken((string) $this->integration->access_token)
            ->timeout(30)
            ->post($url, array_merge([
                'messaging_product' => 'whatsapp',
                'to' => $destinatario,
            ], $cuerpo));

        if (! $respuesta->successful()) {
            /*
             * T-033 · AC-09.3 · El rate limit es el único rechazo que se arregla
             * esperando, así que es el único que se lanza: la cola de T-011 lo
             * reintenta a los 5 y a los 15 segundos.
             *
             * Se registra en `warning` y no en `error`. Un reintento que después
             * sale bien no es un fallo, y contarlo como tal inflaría el conteo de
             * errores por integración que T-039 va a leer. **El `ERROR` definitivo
             * lo escribe `ProcessMessageJob::failed()`**, que es el único que sabe
             * que ya no queda reintento.
             */
            if ($this->esRateLimit($respuesta)) {
                Log::warning('Meta limitó el envío: se reintenta con el backoff de la cola', [
                    'tenant_id' => $conversacion->tenant_id,
                    'conversation_id' => $conversacion->id,
                    'integracion' => 'meta_whatsapp',
                    'codigo' => 'META_RATE_LIMIT',
                    'http' => $respuesta->status(),
                    'error_meta' => $respuesta->json('error.code'),
                ]);

                throw new MetaRateLimit(
                    (string) $conversacion->tenant_id,
                    $respuesta->status(),
                    is_numeric($respuesta->json('error.code')) ? (int) $respuesta->json('error.code') : null,
                );
            }

            /*
             * No se lanza: quien llama ya le respondió `200` a Meta y no puede
             * hacer nada útil con la excepción. Se registra con el código de
             * error, que es lo que T-039 va a leer para detectar los mensajes
             * que no salieron.
             */
            Log::error('Meta rechazó el envío del mensaje', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'integracion' => 'meta_whatsapp',
                'codigo' => 'META_ENVIO_RECHAZADO',
                'http' => $respuesta->status(),
                'error_meta' => $respuesta->json('error.code'),
            ]);

            return null;
        }

        $wamid = $respuesta->json('messages.0.id');

        $this->persistirSaliente($conversacion, $cuerpo, $textoParaElHistorial, $wamid);
        $this->instrumentarLatencia($conversacion, $recibidoEn);

        return $wamid;
    }

    /**
     * ¿Meta nos frenó por volumen?
     *
     * Los tres caminos existen porque Meta usa los tres y no siempre juntos:
     *
     * | Señal | Cuándo llega |
     * | :-- | :-- |
     * | `HTTP 429` | Límite del endpoint de mensajes |
     * | `error.code 130429` | *Rate limit hit* — límite de mensajes por segundo |
     * | `error.code 131048` | *Spam rate limit hit* — límite de calidad del número |
     *
     * Los dos códigos llegan con `HTTP 400`, así que mirar solo el status
     * dejaría afuera el caso más común y lo trataría como rechazo definitivo.
     */
    private function esRateLimit(\Illuminate\Http\Client\Response $respuesta): bool
    {
        if ($respuesta->status() === 429) {
            return true;
        }

        return in_array((int) $respuesta->json('error.code'), [130429, 131048], strict: true);
    }

    /**
     * El saliente va al historial igual que el entrante (T-048).
     *
     * Sin esto, el panel mostraría solo la mitad de la conversación —lo que dijo
     * el cliente— y el operador no vería qué le contestó el bot.
     *
     * @param  array<string,mixed>  $cuerpo
     */
    private function persistirSaliente(Conversation $conversacion, array $cuerpo, string $texto, ?string $wamid): void
    {
        Message::firstOrCreate(
            ['whatsapp_message_id' => $wamid],
            [
                'conversation_id' => $conversacion->id,
                'direction' => Message::SALIENTE,
                'type' => $cuerpo['type'],
                'content' => $texto,
                'payload' => $cuerpo,
            ]
        );
    }

    /**
     * AC-16.1 · El KPI de 15 segundos, medido punta a punta.
     *
     * **Es lo único que el recorte Pareto de T-021 no toca:** es el único lugar
     * donde se mide de verdad, y no por tramos. Todo lo anterior fueron
     * mediciones aisladas —`freeBusy` por un lado, el envío por otro—; acá se
     * cierra el circuito webhook → respuesta.
     *
     * ⚠️ Si esto da por encima de 15 s, el problema es de la cadena entera y no
     * de este ticket. Pero es acá donde se ve por primera vez.
     */
    private function instrumentarLatencia(Conversation $conversacion, ?float $recibidoEn): void
    {
        if ($recibidoEn === null) {
            return;
        }

        $ms = (microtime(true) - $recibidoEn) * 1000;

        Log::info('Respuesta enviada al cliente', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'codigo' => 'KPI_RESPUESTA',
            'latencia_ms' => round($ms),
            // El KPI de negocio son 15 s; el RNF-04 pide 1.500 ms y ya se sabe
            // que no se cumple. Se marcan los dos para poder contar cada uno.
            'dentro_del_kpi' => $ms <= 15_000,
            'dentro_del_rnf04' => $ms <= 1_500,
        ]);
    }

    /**
     * Texto legible de un mensaje interactivo, para el historial.
     *
     * Guardar `[interactive]` dejaría el panel lleno de filas sin contenido: el
     * cuerpo es lo que el cliente realmente leyó.
     *
     * @param  array<string,mixed>  $interactivo
     */
    private function textoLegibleDe(array $interactivo): string
    {
        return $interactivo['body']['text'] ?? '[mensaje interactivo]';
    }
}
