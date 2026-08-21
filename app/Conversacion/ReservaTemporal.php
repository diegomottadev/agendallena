<?php

namespace App\Conversacion;

use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * T-029 · El horario queda apartado desde que el cliente lo elige.
 *
 * ## Por qué la re-consulta a Google no alcanza
 *
 * Entre que el cliente toca el ítem y el evento existe en Google pasan cientos
 * de milisegundos, y **durante toda esa ventana `freeBusy` sigue diciendo que el
 * horario está libre** — porque efectivamente lo está: el evento todavía no se
 * creó. Dos clientes en esa ventana pasan los dos la verificación y se agendan
 * los dos. Lo único que puede impedirlo es un apartado nuestro, anterior a la
 * escritura en Google.
 *
 * ## Es una capa en Redis con TTL, como la pausa
 *
 * Misma forma que `Pausa`: una llave con vencimiento que se consulta antes de
 * decidir. No es un estado de la conversación ni una fila en `bookings` — un
 * horario elegido y no confirmado **no es un turno**, y persistirlo como si lo
 * fuera obligaría a limpiarlo después.
 *
 * ## La exclusión la da Redis, no un `if`
 *
 * `Cache::add()` es `SET NX` sobre el store de Redis: o la llave se crea, o ya
 * estaba. Comprobar con un `has()` y escribir después dejaría abierta la ventana
 * entre las dos consultas, que es literalmente el bug que este ticket arregla
 * movido de lugar. Mismo criterio que el candado de T-037, que se apoya en la
 * restricción única de MySQL.
 *
 * ## Un cliente aparta un solo horario a la vez
 *
 * Decisión del 2026-08-20. Sin esto, alguien que curiosea toca cuatro horarios y
 * bloquea media agenda durante el TTL. Por eso hay una segunda llave —la del
 * cliente— que recuerda cuál es el suyo: al apartar uno nuevo, el anterior se
 * suelta.
 *
 * ⚠️ **Las dos llaves llevan el `tenant_id` adentro.** Sin eso, el primer cliente
 * del día bloquearía las 09:00 de todas las PyMEs del sistema (RNF-01).
 */
class ReservaTemporal
{
    /**
     * Cuánto dura el apartado.
     *
     * ⚠️ El valor correcto no se conoce hasta el piloto: depende de cuánto tarda
     * un cliente entre elegir y que el turno quede creado. Cinco minutos es
     * conservador y la decisión contempla moverlo a diez, así que **se lee de
     * acá y no se escribe el número en ningún otro lado**.
     */
    public const MINUTOS_TTL = 5;

    /**
     * Aparta el horario para este cliente.
     *
     * @return bool  `false` si otro cliente lo tiene apartado.
     */
    public static function apartar(Conversation $conversacion, CarbonImmutable $horario): bool
    {
        $clave = self::claveDelHorario($conversacion->tenant_id, $horario);

        if (Cache::add($clave, $conversacion->user_phone, self::vence())) {
            self::soltarElAnterior($conversacion, $horario);

            Cache::put(self::claveDelCliente($conversacion), $horario->getTimestamp(), self::vence());

            return true;
        }

        /*
         * La llave ya existía. Sigue siendo nuestra solo si el que la tomó fue
         * este mismo cliente —un doble toque, o un reintento de la cola sobre el
         * mismo mensaje—, y en ese caso no es una colisión.
         */
        if (Cache::get($clave) === $conversacion->user_phone) {
            return true;
        }

        Log::info('El horario elegido ya estaba apartado por otro cliente', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'horario' => $horario->utc()->toIso8601String(),
            'codigo' => 'SLOT_APARTADO_POR_OTRO',
        ]);

        return false;
    }

    /**
     * Devuelve el horario al pozo común.
     *
     * Solo si sigue siendo de este cliente: liberar el apartado de otro sería
     * reabrir el choque que el apartado acaba de evitar.
     */
    public static function liberar(Conversation $conversacion, CarbonImmutable $horario): void
    {
        $clave = self::claveDelHorario($conversacion->tenant_id, $horario);

        if (Cache::get($clave) !== $conversacion->user_phone) {
            return;
        }

        Cache::forget($clave);

        if ((int) Cache::get(self::claveDelCliente($conversacion)) === $horario->getTimestamp()) {
            Cache::forget(self::claveDelCliente($conversacion));
        }
    }

    /** ¿Lo tiene apartado alguien que no es este cliente? */
    public static function apartadoPorOtro(string $tenantId, string $telefono, CarbonImmutable $horario): bool
    {
        $duenio = Cache::get(self::claveDelHorario($tenantId, $horario));

        return $duenio !== null && $duenio !== $telefono;
    }

    /**
     * AC-18.3 · Los horarios que todavía se le pueden ofrecer a este cliente.
     *
     * Los que él mismo tiene apartados **no se filtran**: sacárselos de la lista
     * le escondería justo el horario que está por elegir.
     *
     * @param  array<int,CarbonImmutable>  $horarios
     * @return array<int,CarbonImmutable>
     */
    public static function sinLosApartadosPorOtros(array $horarios, string $tenantId, string $telefono): array
    {
        return array_values(array_filter(
            $horarios,
            fn (CarbonImmutable $h) => ! self::apartadoPorOtro($tenantId, $telefono, $h),
        ));
    }

    /**
     * Suelta el horario que este cliente tenía apartado antes de elegir otro.
     */
    private static function soltarElAnterior(Conversation $conversacion, CarbonImmutable $nuevo): void
    {
        $anterior = Cache::get(self::claveDelCliente($conversacion));

        if ($anterior === null || (int) $anterior === $nuevo->getTimestamp()) {
            return;
        }

        $clave = self::claveDelHorario(
            $conversacion->tenant_id,
            CarbonImmutable::createFromTimestamp((int) $anterior, 'UTC'),
        );

        // Solo si sigue siendo suyo: pudo haber vencido y haberlo tomado otro.
        if (Cache::get($clave) === $conversacion->user_phone) {
            Cache::forget($clave);
        }
    }

    private static function vence(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(self::MINUTOS_TTL);
    }

    /**
     * El instante va en la llave como timestamp Unix: es UTC por definición, así
     * que no arrastra la ambigüedad de una hora local sin huso.
     */
    private static function claveDelHorario(string $tenantId, CarbonImmutable $horario): string
    {
        return "hold:tenant:{$tenantId}:slot:{$horario->getTimestamp()}";
    }

    /** Cuál es el único horario que este cliente tiene apartado. */
    private static function claveDelCliente(Conversation $conversacion): string
    {
        return "hold:tenant:{$conversacion->tenant_id}:phone:{$conversacion->user_phone}";
    }
}
