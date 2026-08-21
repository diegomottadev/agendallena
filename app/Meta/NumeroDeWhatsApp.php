<?php

namespace App\Meta;

/**
 * Normalización del teléfono para enviarle a Meta.
 *
 * ## El hueco que cierra
 *
 * Verificado empíricamente el 2026-08-19 contra el número de prueba:
 *
 * | Operación | Resultado |
 * | :-- | :-- |
 * | Enviar a `543764278402` *(sin el 9)* | ✅ entregado |
 * | Enviar a `5493764278402` *(con el 9)* | ❌ `(#131030)` |
 * | El `wa_id` que **devuelve** Meta | `5493764278402` *(con el 9)* |
 *
 * **Meta acepta el envío sin el 9 y devuelve el identificador con el 9.** El
 * webhook entrante trae ese `wa_id` en `messages[].from`, así que el reflejo
 * natural —guardar el `from` y responderle— **falla**. Y falla en producción,
 * con clientes reales, con el síntoma *"el bot no contesta"*.
 *
 * Argentina es el mercado inicial: no es un caso borde. **Ningún ticket del
 * backlog contempla esto**; ver `plan-for-diego/numeros-argentinos-el-9.md`.
 *
 * ## Por qué no es un `str_replace`
 *
 * El 9 solo sobra en Argentina. México tiene una historia parecida con el 1 —que
 * WhatsApp ya no exige— y en el resto de los países el noveno dígito es parte
 * del número. Un reemplazo ciego rompería números que están bien.
 *
 * ⚠️ **Verificado solo contra un número de prueba**, cuya lista de destinatarios
 * autorizados compara el formato literalmente. Es posible que en un número de
 * producción el formato con 9 también funcione. Lo que sí está probado sin
 * ambigüedad es la asimetría entre lo que se manda y lo que se recibe.
 */
class NumeroDeWhatsApp
{
    private const ARGENTINA = '54';

    /**
     * El número tal como hay que mandárselo a Meta.
     *
     * Recibe el `wa_id` del webhook —o cualquier variante que haya escrito una
     * persona— y devuelve la forma que la API acepta.
     */
    public static function paraEnviar(string $numero): string
    {
        $digitos = self::soloDigitos($numero);

        if (! str_starts_with($digitos, self::ARGENTINA)) {
            // Fuera de Argentina no se toca: el noveno dígito es parte del número.
            return $digitos;
        }

        /*
         * Argentina: `54` + `9` + área + abonado. El 9 es el prefijo de móvil que
         * WhatsApp devuelve pero rechaza al enviar.
         *
         * Se exige una longitud plausible antes de sacarlo: sin ese chequeo, un
         * fijo cuyo primer dígito de área sea 9 —como `54 9xxx …`— perdería un
         * dígito real y el mensaje iría a un número inexistente.
         */
        if (str_starts_with($digitos, self::ARGENTINA.'9') && strlen($digitos) >= 13) {
            return self::ARGENTINA.substr($digitos, 3);
        }

        return $digitos;
    }

    /**
     * La forma canónica para **guardar** y comparar: la que Meta devuelve.
     *
     * Se guarda el `wa_id` y no la forma de envío porque es lo que llega en cada
     * webhook: normalizar al guardar significaría convertir en cada mensaje
     * entrante, y una conversión de más es una oportunidad de más de no coincidir.
     */
    public static function paraGuardar(string $numero): string
    {
        $digitos = self::soloDigitos($numero);

        if (! str_starts_with($digitos, self::ARGENTINA)) {
            return $digitos;
        }

        // Móvil argentino sin el 9: se lo agrega para que coincida con el `wa_id`.
        if (! str_starts_with($digitos, self::ARGENTINA.'9') && strlen($digitos) >= 12) {
            return self::ARGENTINA.'9'.substr($digitos, 2);
        }

        return $digitos;
    }

    /** ¿Dos escrituras distintas son el mismo teléfono? */
    public static function sonElMismo(string $a, string $b): bool
    {
        return self::paraGuardar($a) === self::paraGuardar($b);
    }

    private static function soloDigitos(string $numero): string
    {
        return preg_replace('/\D+/', '', $numero) ?? '';
    }
}
