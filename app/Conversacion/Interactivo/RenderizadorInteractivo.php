<?php

namespace App\Conversacion\Interactivo;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

/**
 * T-019 · Arma el cuerpo de un mensaje interactivo de Meta.
 *
 * ## La regla que ordena esta clase
 *
 * **Nunca falla el envío.** Un mensaje que excede un límite se recorta o se
 * pagina *antes* de salir; no se manda y se ve qué pasa. La razón está en el
 * contexto de T-024: para el cliente, un mensaje que Meta rechaza y un bot que
 * no contesta son **exactamente lo mismo** — silencio.
 *
 * Todos los textos pasan por `LimitesDeMeta::recortar()` aunque los escribamos
 * nosotros: el dueño de la PyME también escribe textos, desde T-017.
 */
class RenderizadorInteractivo
{
    /**
     * Botones de respuesta rápida. Hasta tres; con más, va lista.
     *
     * @param  array<int,Opcion>  $opciones
     * @return array<string,mixed>  cuerpo listo para el endpoint de Meta
     */
    public function botones(Conversation $conversacion, string $cuerpo, array $opciones): array
    {
        if (count($opciones) > LimitesDeMeta::MAX_BOTONES) {
            /*
             * Se avisa fuerte: llamar a `botones()` con más de tres es un error
             * de programación, no un caso de uso. Se recortan igual —silencio es
             * peor— pero alguien tiene que enterarse de que se perdieron
             * opciones.
             */
            Log::warning('Se pidieron más botones de los que Meta admite: se recortan', [
                'tenant_id' => $conversacion->tenant_id,
                'pedidos' => count($opciones),
                'maximo' => LimitesDeMeta::MAX_BOTONES,
                'codigo' => 'META_BOTONES_RECORTADOS',
            ]);

            $opciones = array_slice($opciones, 0, LimitesDeMeta::MAX_BOTONES);
        }

        return [
            'type' => 'button',
            'body' => ['text' => LimitesDeMeta::recortar($cuerpo, LimitesDeMeta::MAX_CUERPO)],
            'action' => [
                'buttons' => array_map(fn (Opcion $o) => [
                    'type' => 'reply',
                    'reply' => [
                        'id' => (string) IdSellado::emitir($o->accion, $conversacion),
                        'title' => LimitesDeMeta::recortar($o->titulo, LimitesDeMeta::MAX_TEXTO_BOTON),
                    ],
                ], array_values($opciones)),
            ],
        ];
    }

    /**
     * Lista interactiva. **Devuelve una página**, no el mensaje entero.
     *
     * @param  array<int,Opcion>  $opciones
     * @return array<string,mixed>
     */
    public function lista(
        Conversation $conversacion,
        string $cuerpo,
        string $textoBoton,
        array $opciones,
        ?string $tituloSeccion = null,
    ): array {
        $opciones = array_slice(array_values($opciones), 0, LimitesDeMeta::MAX_FILAS_LISTA);

        return [
            'type' => 'list',
            'body' => ['text' => LimitesDeMeta::recortar($cuerpo, LimitesDeMeta::MAX_CUERPO)],
            'action' => [
                'button' => LimitesDeMeta::recortar($textoBoton, LimitesDeMeta::MAX_TEXTO_BOTON_LISTA),
                'sections' => [[
                    'title' => LimitesDeMeta::recortar($tituloSeccion ?? 'Opciones', LimitesDeMeta::MAX_TITULO_SECCION),
                    'rows' => array_map(function (Opcion $o) use ($conversacion) {
                        $fila = [
                            'id' => (string) IdSellado::emitir($o->accion, $conversacion),
                            'title' => LimitesDeMeta::recortar($o->titulo, LimitesDeMeta::MAX_TITULO_FILA),
                        ];

                        if ($o->descripcion !== null) {
                            $fila['description'] = LimitesDeMeta::recortar(
                                $o->descripcion, LimitesDeMeta::MAX_DESCRIPCION_FILA
                            );
                        }

                        return $fila;
                    }, $opciones),
                ]],
            ],
        ];
    }

    /**
     * Parte una lista larga en páginas que entran en los límites.
     *
     * La última fila de cada página, salvo la última, es **"Ver más"**: por eso
     * cada página lleva `MAX_FILAS_LISTA - 1` opciones reales. Sin esa fila, el
     * cliente ve diez horarios y no tiene forma de pedir los siguientes — y
     * paginar sin salida es peor que no paginar.
     *
     * @param  array<int,Opcion>  $opciones
     * @return array<int,array<int,Opcion>>
     */
    public function paginar(array $opciones, string $accionVerMas = 'ver_mas'): array
    {
        $opciones = array_values($opciones);

        if (count($opciones) <= LimitesDeMeta::MAX_FILAS_LISTA) {
            return [$opciones];
        }

        $porPagina = LimitesDeMeta::MAX_FILAS_LISTA - 1;
        $paginas = array_chunk($opciones, $porPagina);

        foreach ($paginas as $i => $pagina) {
            if ($i < count($paginas) - 1) {
                $paginas[$i][] = new Opcion($accionVerMas.'_'.($i + 1), 'Ver más opciones');
            }
        }

        return $paginas;
    }
}
