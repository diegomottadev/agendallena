<?php

namespace App\Recordatorios;

use App\Conversacion\Interactivo\IdSellado;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Support\HoraLocal;

/**
 * T-037 · El cuerpo de la plantilla aprobada en Meta (T-002).
 *
 * ## Los cuatro parámetros son posicionales y no toleran error
 *
 * Verificado contra Meta real: la plantilla declara `{{1}}` a `{{4}}` y mandar
 * uno de más o de menos hace que **rechace el mensaje entero**. No es una
 * preferencia de forma — es la diferencia entre que el recordatorio llegue y que
 * no llegue, que es el número con el que se cierran las ventas.
 *
 * ## Por qué el payload de los botones va sellado
 *
 * Los botones de una plantilla aprobada no llevan `id` propio: llevan el
 * `payload` que se fija **en cada envío** y que Meta devuelve tal cual cuando el
 * cliente lo toca. Se emite con el sello de T-019 para que
 * `InterpreteDeRespuesta` lo pueda leer, y para que un botón fuera de paso se
 * pueda rechazar por lo que el sello hace bien.
 *
 * ⚠️ **A qué turno pertenece la respuesta no lo resuelve el sello**, sino el
 * `context.id` del webhook contra `notification_logs`. Ver `RespuestaAlRecordatorio`.
 */
class PlantillaRecordatorio24h
{
    /** Nombre exacto de la plantilla aprobada por Meta (T-002). */
    public const NOMBRE = 'recordatorio_turno_24h';

    /** El idioma también lo fija la aprobación: con otro, Meta rechaza el envío. */
    public const IDIOMA = 'es_AR';

    public const CONFIRMAR = 'confirmar';

    /** ⚠️ Sin efecto sobre la reserva hasta T-042, que está diferido. */
    public const REAGENDAR = 'reagendar';

    public const CANCELAR = 'cancelar';

    /**
     * Acción de cada botón **por su índice**, en el orden de la plantilla
     * aprobada. Cambiar el orden acá sin cambiarlo en el portal de Meta haría
     * que Confirmar cancele turnos, y no habría ningún error que lo avisara.
     */
    public const BOTONES = [
        0 => self::CONFIRMAR,
        1 => self::REAGENDAR,
        2 => self::CANCELAR,
    ];

    /**
     * @return array<string,mixed>  Lo que va bajo `template` en el envío.
     */
    public static function armar(Booking $booking, Tenant $tenant, Conversation $conversacion): array
    {
        return [
            'name' => self::NOMBRE,
            'language' => ['code' => self::IDIOMA],
            'components' => array_merge([self::cuerpo($booking, $tenant)], self::botones($conversacion)),
        ];
    }

    /** Lo que se guarda en el historial: la plantilla no se lee sola en el panel. */
    public static function textoParaElHistorial(Booking $booking, Tenant $tenant): string
    {
        return "Recordatorio del turno de {$booking->client_name}: "
            .$booking->service_name.' · '.self::cuando($booking, $tenant);
    }

    /**
     * @return array<string,mixed>
     */
    private static function cuerpo(Booking $booking, Tenant $tenant): array
    {
        return [
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => (string) $booking->client_name],
                ['type' => 'text', 'text' => (string) $tenant->name],
                ['type' => 'text', 'text' => (string) $booking->service_name],
                ['type' => 'text', 'text' => self::cuando($booking, $tenant)],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function botones(Conversation $conversacion): array
    {
        $componentes = [];

        foreach (self::BOTONES as $indice => $accion) {
            $componentes[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                // Meta lo espera como string, igual que en la documentación.
                'index' => (string) $indice,
                'parameters' => [[
                    'type' => 'payload',
                    'payload' => (string) IdSellado::emitir($accion, $conversacion),
                ]],
            ];
        }

        return $componentes;
    }

    /**
     * AC-07.4 · La hora **en la zona del tenant**.
     *
     * Es el error más caro del producto porque es silencioso: no hay excepción
     * ni 500, solo un cliente que llega horas tarde.
     */
    private static function cuando(Booking $booking, Tenant $tenant): string
    {
        return HoraLocal::completa($booking->start_time, $tenant);
    }
}
