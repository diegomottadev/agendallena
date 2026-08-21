<?php

namespace App\Conversacion\Interactivo;

/**
 * Qué resultó de interpretar la respuesta del cliente.
 *
 * Son cuatro casos y **cada uno pide una respuesta distinta del bot**. Colapsarlos
 * en un `?string` obligaría a quien llama a adivinar la diferencia entre "el
 * cliente escribió texto" y "tocó un botón viejo", que no se parecen en nada.
 */
class RespuestaInteractiva
{
    private function __construct(
        public readonly TipoRespuesta $tipo,
        public readonly ?IdSellado $sello = null,
        public readonly ?string $idCrudo = null,
    ) {}

    /** El cliente tocó un botón del paso actual: se ejecuta la acción. */
    public static function vigente(IdSellado $sello): self
    {
        return new self(TipoRespuesta::Vigente, $sello);
    }

    /**
     * Tocó un botón de un mensaje anterior (AC-03.2).
     *
     * El bot **no ejecuta la acción** y le responde con el paso actual.
     */
    public static function caduca(IdSellado $sello): self
    {
        return new self(TipoRespuesta::Caduca, $sello);
    }

    /** Un `id` con forma de botón que este código no emitió. */
    public static function idDesconocido(string $id): self
    {
        return new self(TipoRespuesta::Desconocida, null, $id);
    }

    /** Texto libre, audio, imagen: no hay botón que resolver. */
    public static function noInteractiva(): self
    {
        return new self(TipoRespuesta::NoInteractiva);
    }

    /** ¿Se puede ejecutar la acción que trae? */
    public function esAccionable(): bool
    {
        return $this->tipo === TipoRespuesta::Vigente;
    }

    /** La acción, solo si es accionable. */
    public function accion(): ?string
    {
        return $this->esAccionable() ? $this->sello?->accion : null;
    }
}
