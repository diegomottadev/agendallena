<?php

namespace App\Conversacion\Interactivo;

/**
 * Los límites de la API de mensajes interactivos de Meta.
 *
 * ⚠️ **Estos números hay que verificarlos contra la documentación vigente.** El
 * ticket lo advierte explícitamente: *"cambian entre versiones"*. Al escribir
 * esto el token de la cuenta de prueba estaba vencido y no se pudieron
 * comprobar contra la API real — **están tomados de la documentación conocida,
 * no medidos**.
 *
 * ## Por qué eso importa menos de lo que parece
 *
 * El criterio del ticket no es *"acertar los números"* sino **"un mensaje que
 * excede los límites se recorta o pagina antes de enviarse: nunca falla el
 * envío"**. La estrategia importa más que la constante:
 *
 * - Si un número acá es **más chico** que el real, se pagina de más. Feo, no roto.
 * - Si es **más grande**, Meta rechaza el mensaje entero y el cliente no recibe
 *   nada. Por eso, ante la duda, van los valores conservadores.
 *
 * Cuando haya un token vivo, `RenderizadorInteractivoTest` sirve de base para
 * medirlos: se manda un mensaje al borde de cada límite y se mira qué contesta
 * Meta.
 */
final class LimitesDeMeta
{
    // --- Quick Reply buttons ---

    /** Botones por mensaje. Con más, hay que usar una lista. */
    public const MAX_BOTONES = 3;

    /** Caracteres del texto visible de un botón. */
    public const MAX_TEXTO_BOTON = 20;

    // --- List Messages ---

    /** Filas en total, sumando todas las secciones. */
    public const MAX_FILAS_LISTA = 10;

    /** Secciones por lista. */
    public const MAX_SECCIONES = 10;

    /** Caracteres del título de una fila. */
    public const MAX_TITULO_FILA = 24;

    /** Caracteres de la descripción de una fila. */
    public const MAX_DESCRIPCION_FILA = 72;

    /** Caracteres del título de una sección. */
    public const MAX_TITULO_SECCION = 24;

    /** Texto del botón que abre la lista. */
    public const MAX_TEXTO_BOTON_LISTA = 20;

    // --- Comunes ---

    /** Cuerpo del mensaje. */
    public const MAX_CUERPO = 1024;

    /** Encabezado, cuando es de texto. */
    public const MAX_ENCABEZADO = 60;

    /** Pie del mensaje. */
    public const MAX_PIE = 60;

    /**
     * Recorta un texto al límite **sin cortar una palabra al medio**.
     *
     * ⚠️ El recorte Pareto difiere el "truncado legible" porque los textos son
     * nuestros y de T-017, y se escriben cortos. Se implementa igual porque son
     * seis líneas y evita el modo de falla que el recorte no cubre: **un texto
     * que el dueño de la PyME escribe largo en el panel**. Sin esto, ese mensaje
     * no se recorta: falla el envío entero y el cliente no recibe nada.
     */
    public static function recortar(string $texto, int $maximo): string
    {
        $texto = trim($texto);

        if (mb_strlen($texto) <= $maximo) {
            return $texto;
        }

        // Se reserva un carácter para el puntito de continuación.
        $corte = mb_substr($texto, 0, $maximo - 1);
        $ultimoEspacio = mb_strrpos($corte, ' ');

        // Si la última palabra es enorme, cortar por espacio dejaría casi nada:
        // ahí se corta a lo bruto, que sigue siendo mejor que fallar el envío.
        if ($ultimoEspacio !== false && $ultimoEspacio > $maximo * 0.6) {
            $corte = mb_substr($corte, 0, $ultimoEspacio);
        }

        return rtrim($corte).'…';
    }
}
