<?php

namespace App\Conversacion;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * T-035 · Derivar la conversación a una persona, y que la persona se entere.
 *
 * ## El problema que cierra
 *
 * Tres caminos del flujo terminan en *"un asesor te va a atender"* y ninguno le
 * avisa al asesor. Desde el lado del cliente la promesa se hizo; desde el lado
 * de la PyME no pasó nada. Y se activa con clientes que **querían comprar**.
 *
 * ## El silencio del bot no es un estado nuevo
 *
 * US-20 lo pide explícito: se reusa la llave `pause:tenant:{id}:phone:{phone}`
 * de T-018b, *"el mismo mecanismo, disparado por nosotros en vez de por el
 * operador"*. Por eso acá no hay ni un estado `HANDOFF` ni una transición: la
 * conversación **conserva `current_state`** y el bot se calla por encima. Si la
 * persona no la resuelve, el TTL de la pausa la devuelve sola — que es lo que
 * AC-20.3 llama *"o venza la ventana de derivación"*.
 *
 * ## Lo que sigue abierto
 *
 * ⚠️ **El aviso es pasivo:** la lista del panel y nada más. Avisar por WhatsApp
 * al dueño consumiría una plantilla de Meta que todavía está en revisión, y es
 * la misma decisión que arrastra T-039. El punto de extensión es el final de
 * `activar()`.
 *
 * ⚠️ **Sin definir qué pasa con una derivación que nadie atiende.** No hay
 * ventana de vencimiento del pendiente: una derivación sin resolver se queda en
 * la lista para siempre, a la vista. Es preferible a inventar un vencimiento que
 * la haga desaparecer sin que nadie la haya atendido.
 */
class Derivacion
{
    /**
     * Cuántas entradas seguidas sin entender antes de llamar a una persona.
     *
     * **Tres, y el número es una elección, no un dato del ticket** —US-20 lo
     * deja sin definir—. Al segundo intento fallido el cliente ya se está
     * frustrando y al cuarto se va: tres deja margen para un error de tipeo o un
     * mensaje mal mandado sin convertir el chat en un interrogatorio. Se sube o
     * se baja mirando el conteo por motivo que esta misma clase deja registrado.
     */
    public const INTENTOS_PARA_DERIVAR = 3;

    /**
     * La acción del botón «Hablar con una persona» que ofrece T-024.
     *
     * El botón ya se ofrecía cuando la agenda está llena y **no hacía nada**:
     * era una de las tres promesas incumplidas que US-20 vino a cerrar.
     */
    public const ACCION = 'hablar_con_persona';

    /**
     * Cuánto se recuerda un intento fallido.
     *
     * Los mismos 30 minutos que la inactividad de T-007: tres mensajes sueltos a
     * lo largo de una tarde no son un cliente frustrado, son tres conversaciones
     * distintas. Cada intento fallido reinicia la ventana, así que los tres
     * tienen que caer dentro de la misma charla.
     */
    private const MINUTOS_DE_MEMORIA = 30;

    /**
     * Lo que se le contesta al cliente cuando se lo deriva.
     *
     * ⚠️ **No es configurable por el negocio.** T-017 define tres textos
     * —bienvenida, cortesía y sin disponibilidad— y este sería un cuarto, con su
     * campo, su validación y su pantalla. Queda hardcodeado a propósito hasta
     * que un piloto pida cambiarlo.
     */
    /**
     * ⚠️ **Este texto decía «te va a escribir por acá» y era mentira.**
     *
     * Un número registrado en la Cloud API **deja de poder usarse como chat
     * humano**: o es bot, o es persona. Nadie puede escribirle al cliente por
     * este número, así que prometerlo mandaba a esperar una respuesta que no
     * iba a llegar nunca.
     *
     * Se conserva como último recurso, para el tenant que todavía no cargó su
     * número de atención. **No promete que alguien escriba**: dice lo único que
     * es cierto, que la consulta quedó registrada.
     */
    public const MENSAJE_SIN_NUMERO = 'Le paso tu consulta a una persona del equipo. '
        .'Te contactamos a la brevedad.';

    /**
     * El mensaje de derivación, con el número por el que atiende una persona.
     *
     * Decir el número **es el mensaje**: con dos números, el cliente tiene que
     * cambiar de chat, y si no le decimos a cuál, la derivación no lleva a
     * ningún lado.
     */
    public static function mensajePara(?\App\Models\BusinessSetting $config): string
    {
        $numero = trim((string) ($config?->human_phone ?? ''));

        if ($numero === '') {
            return self::MENSAJE_SIN_NUMERO;
        }

        return 'Le paso tu consulta a una persona del equipo. '
            ."Escribinos a este número y te atendemos: {$numero}";
    }

    /**
     * Marca la conversación como derivada y calla al bot.
     *
     * @return bool  `true` si **esta** llamada creó el pendiente. `false` si ya
     *   había uno: quien llama usa ese valor para no repetirle el mensaje al
     *   cliente (AC-20.4).
     */
    public static function activar(Conversation $conversacion, MotivoDeDerivacion $motivo): bool
    {
        // El contador de intentos ya cumplió su función: si el cliente vuelve
        // después de que la deriven, arranca de cero.
        self::olvidarIntentos($conversacion);

        if (self::estaPendiente($conversacion)) {
            /*
             * AC-20.4 · Ya hay un pendiente. No se crea otro, no se le repite el
             * mensaje al cliente y **no se pisa el motivo original**: el primero
             * es el que explica por qué se le prometió una persona.
             *
             * Se vuelve a callar al bot igual. Llegar hasta acá con un pendiente
             * abierto significa que la ventana de la pausa venció y el bot
             * retomó (AC-20.3 lo permite), pero el cliente sigue esperando a
             * alguien: dejar que el bot le conteste por encima sería peor.
             */
            Pausa::activar($conversacion, Pausa::ORIGEN_DERIVACION);

            Log::info('Segunda condición de derivación sobre una conversación ya derivada', [
                'tenant_id' => $conversacion->tenant_id,
                'conversation_id' => $conversacion->id,
                'motivo_original' => $conversacion->handoff_reason,
                'motivo_nuevo' => $motivo->value,
                'codigo' => 'DERIVACION_DUPLICADA',
            ]);

            return false;
        }

        /*
         * AC-20.1 · Motivo y hora. `now()` es UTC (RNF-02): la conversión a la
         * zona del negocio ocurre recién en el panel.
         *
         * `forceFill` + `save` y no la máquina de estados: la derivación **no es
         * un estado**. `current_state` queda intacto para que, al resolverla, el
         * cliente retome desde donde estaba en vez de volver a empezar.
         */
        $conversacion->forceFill([
            'handoff_reason' => $motivo->value,
            'handoff_at' => now(),
            'handoff_resolved_at' => null,
        ])->save();

        Pausa::activar($conversacion, Pausa::ORIGEN_DERIVACION);

        Log::info('Conversación derivada a una persona', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'motivo' => $motivo->value,
            'estado_conservado' => $conversacion->current_state,
            'codigo' => 'DERIVACION_ACTIVADA',
        ]);

        /*
         * ⚠️ Punto de extensión del aviso activo. Acá —y en ningún otro lado— es
         * donde se sabe que hay alguien esperando. Cuando se resuelva la decisión
         * del canal (la misma de T-039), el envío al dueño va en esta línea.
         */

        return true;
    }

    /** ¿Hay alguien esperando que una persona le conteste? */
    public static function estaPendiente(Conversation $conversacion): bool
    {
        return $conversacion->handoff_at !== null
            && $conversacion->handoff_resolved_at === null;
    }

    /**
     * Alguien la atendió: se cierra el pendiente y el bot vuelve al flujo.
     *
     * No se borran `handoff_reason` ni `handoff_at`: son el histórico por causa.
     */
    public static function resolver(Conversation $conversacion, ?string $usuarioId = null): void
    {
        $conversacion->forceFill(['handoff_resolved_at' => now()])->save();

        // El bot vuelve **desde el estado en que había quedado**: la pausa vivía
        // en Redis justamente para no haber tocado `current_state`.
        Pausa::levantar($conversacion, $usuarioId);
        self::olvidarIntentos($conversacion);

        Log::info('Derivación resuelta: la conversación vuelve al flujo automático', [
            'tenant_id' => $conversacion->tenant_id,
            'conversation_id' => $conversacion->id,
            'motivo' => $conversacion->handoff_reason,
            'usuario_id' => $usuarioId,
            'estado_retomado' => $conversacion->current_state,
            'codigo' => 'DERIVACION_RESUELTA',
        ]);
    }

    /**
     * AC-20.2 · Los pendientes del tenant activo, **el más viejo primero**.
     *
     * El orden no es cosmético: el que lleva más tiempo esperando es el que está
     * más cerca de irse.
     */
    public static function pendientes(): Builder
    {
        return Conversation::query()
            ->whereNotNull('handoff_at')
            ->whereNull('handoff_resolved_at')
            ->orderBy('handoff_at');
    }

    /**
     * Suma uno al contador de entradas no reconocidas y devuelve el total.
     *
     * Vive en Redis y no en `context_data` a propósito: escribir el contexto
     * fuera de la máquina de estados es pisar el dato que otro worker puede
     * estar fusionando, y esto no es estado de la conversación sino una racha
     * que caduca sola.
     */
    public static function contarIntentoFallido(Conversation $conversacion): int
    {
        $intentos = ((int) Cache::get(self::claveDeIntentos($conversacion), 0)) + 1;

        Cache::put(
            self::claveDeIntentos($conversacion),
            $intentos,
            now()->addMinutes(self::MINUTOS_DE_MEMORIA),
        );

        return $intentos;
    }

    /** El bot entendió: la racha se corta. */
    public static function olvidarIntentos(Conversation $conversacion): void
    {
        Cache::forget(self::claveDeIntentos($conversacion));
    }

    /**
     * RNF-01 · Con el tenant adentro, como toda llave del proyecto: sin eso, dos
     * PyMEs con un cliente del mismo número comparten el contador.
     */
    private static function claveDeIntentos(Conversation $c): string
    {
        return "handoff:intentos:tenant:{$c->tenant_id}:phone:{$c->user_phone}";
    }
}
