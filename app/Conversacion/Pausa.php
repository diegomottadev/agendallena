<?php

namespace App\Conversacion;

use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * T-018b · La pausa por intervención humana.
 *
 * ## Es una capa, no un estado
 *
 * El catálogo de flujos declara `PAUSED_HUMAN` como estado, y la matriz dice que
 * al vencer el TTL se va a `IDLE`. **Eso contradice a AC-08.2 y AC-21.2**, que
 * piden que el bot *"retome desde el estado en que quedó"* — y AC-08.2 lo dice
 * sin ambigüedad: *"conservar `conversations.current_state` intacto durante la
 * pausa"*.
 *
 * Gana el criterio de aceptación. Mandar a `IDLE` significaría que un cliente a
 * mitad de una reserva, al que el operador le contestó una duda, **pierde todo
 * el flujo** y tiene que empezar de nuevo. Es justo la fricción que el producto
 * viene a eliminar.
 *
 * Por eso la pausa vive en **Redis y no en `current_state`**: una llave con TTL
 * que se consulta antes de responder. El estado de la conversación no se toca.
 *
 * ⚠️ **`PAUSED_HUMAN` queda declarado en el enum pero sin usarse en el camino
 * normal.** Es deliberado: el catálogo lo nombra y T-025 va a querer mostrarlo
 * en el panel. Se marca acá para que la próxima persona no crea que se olvidó.
 */
class Pausa
{
    /** RF-A4 y AC-08.2 · Sesenta minutos. */
    public const MINUTOS = 60;

    public const ORIGEN_MANUAL = 'panel';

    public const ORIGEN_AUTOMATICO = 'mensaje_manual';

    /**
     * T-035 · La pausa que dispara el bot al derivar a una persona.
     *
     * Es un origen propio y no `ORIGEN_AUTOMATICO` porque las dos se leen
     * distinto en el panel: la automática significa *"alguien del equipo ya está
     * contestando"* y esta significa lo contrario — **nadie contestó todavía** y
     * hay un cliente esperando.
     */
    public const ORIGEN_DERIVACION = 'derivacion';

    /**
     * Silencia al bot para esta conversación.
     *
     * @param  string|null  $usuarioId  Quién la pausó, si fue desde el panel.
     */
    public static function activar(
        Conversation $conversacion,
        string $origen = self::ORIGEN_AUTOMATICO,
        ?string $usuarioId = null,
    ): void {
        Cache::put(
            self::clave($conversacion),
            [
                'origen' => $origen,
                'usuario_id' => $usuarioId,
                'desde' => CarbonImmutable::now()->toIso8601String(),
            ],
            now()->addMinutes(self::MINUTOS),
        );

        Log::info('Bot pausado para una conversación', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'origen' => $origen,
            'usuario_id' => $usuarioId,
            'estado_conservado' => $conversacion->current_state,
            'codigo' => 'PAUSA_ACTIVADA',
        ]);
    }

    /** AC-21.2 · Reactivación anticipada, sin esperar el vencimiento. */
    public static function levantar(Conversation $conversacion, ?string $usuarioId = null): void
    {
        Cache::forget(self::clave($conversacion));

        Log::info('Bot reactivado', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'usuario_id' => $usuarioId,
            'estado_retomado' => $conversacion->current_state,
            'codigo' => 'PAUSA_LEVANTADA',
        ]);
    }

    public static function estaPausada(Conversation $conversacion): bool
    {
        return Cache::has(self::clave($conversacion));
    }

    /**
     * Datos de la pausa vigente: origen, quién y desde cuándo.
     *
     * Los consume T-025 para mostrar en el panel *"quién la pausó y cuándo,
     * distinguible de una pausa detectada automáticamente"* (AC-21.4).
     *
     * @return array<string,mixed>|null
     */
    public static function detalle(Conversation $conversacion): ?array
    {
        $datos = Cache::get(self::clave($conversacion));

        if (! is_array($datos)) {
            return null;
        }

        $desde = CarbonImmutable::parse($datos['desde']);

        return $datos + [
            'vence' => $desde->addMinutes(self::MINUTOS),
            // AC-21.1 · El tiempo restante, para mostrarlo en el panel.
            'minutos_restantes' => max(0, (int) ceil(
                CarbonImmutable::now()->diffInMinutes($desde->addMinutes(self::MINUTOS), false)
            )),
        ];
    }

    /**
     * Refresca el TTL: cada mensaje manual del operador reinicia los 60 minutos.
     *
     * Sin esto, una conversación larga con el operador se destaparía a mitad de
     * camino y el bot empezaría a contestar por encima suyo (AC-08.1).
     */
    public static function refrescar(Conversation $conversacion): void
    {
        $detalle = Cache::get(self::clave($conversacion));

        if (is_array($detalle)) {
            self::activar($conversacion, $detalle['origen'], $detalle['usuario_id'] ?? null);
        }
    }

    /**
     * La llave del catálogo de flujos §1.
     *
     * AC-08.3 · Lleva el teléfono además del tenant: pausar una conversación no
     * puede callar al bot para los demás clientes de la misma PyME.
     */
    private static function clave(Conversation $c): string
    {
        return "pause:tenant:{$c->tenant_id}:phone:{$c->user_phone}";
    }
}
