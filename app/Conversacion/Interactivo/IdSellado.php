<?php

namespace App\Conversacion\Interactivo;

use App\Conversacion\Estado;
use App\Models\Conversation;

/**
 * T-019 · El `id` de cada botón lleva sellado de dónde salió (AC-03.2).
 *
 * ## El problema que resuelve
 *
 * WhatsApp no borra los mensajes viejos. El cliente scrollea, encuentra un
 * mensaje de hace veinte minutos y toca un botón. Sin sello, el bot ejecuta esa
 * acción **como si fuera la respuesta al paso actual** — cancela un turno que
 * el cliente acababa de confirmar, o elige un horario que ya no existe.
 *
 * ## Formato
 *
 * ```
 * accion|ESTADO_EMISOR|version
 * ```
 *
 * Ejemplo: `slot_2|SELECTING_SLOT|4`
 *
 * Va en claro y no firmado: **el `id` del botón no viaja por afuera.** Meta lo
 * emite y Meta lo devuelve; el cliente no puede fabricarlo sin tocar la app.
 * Firmarlo gastaría los 256 caracteres del campo sin agregar seguridad real.
 *
 * ## Los dos componentes del sello
 *
 * - **El estado emisor** frena el botón de un paso anterior.
 * - **La versión** frena el botón de una vuelta anterior *al mismo paso*: sin
 *   ella, una lista de horarios vieja seguiría siendo válida después de que la
 *   conversación reinicie y vuelva a `SELECTING_SLOT` con horarios distintos.
 */
class IdSellado
{
    /** Tope del campo `id` en la API de Meta. Ver la nota de límites. */
    public const MAX_LONGITUD = 256;

    private const SEPARADOR = '|';

    private function __construct(
        public readonly string $accion,
        public readonly Estado $estadoEmisor,
        public readonly int $version,
    ) {}

    /** Sella una acción con el estado y la versión actuales de la conversación. */
    public static function emitir(string $accion, Conversation $conversacion): self
    {
        return new self(
            $accion,
            Estado::from($conversacion->current_state),
            (int) $conversacion->state_version,
        );
    }

    public function __toString(): string
    {
        return $this->accion.self::SEPARADOR.$this->estadoEmisor->value.self::SEPARADOR.$this->version;
    }

    /**
     * Lee un `id` recibido. Devuelve `null` si no tiene el formato del sello.
     *
     * Nunca lanza: un `id` desconocido puede venir de una plantilla vieja o de
     * un mensaje que no emitimos nosotros, y eso no es motivo para romper el
     * procesamiento del webhook.
     */
    public static function leer(?string $id): ?self
    {
        if (! is_string($id)) {
            return null;
        }

        $partes = explode(self::SEPARADOR, $id);

        if (count($partes) !== 3) {
            return null;
        }

        [$accion, $estado, $version] = $partes;

        $estadoEmisor = Estado::tryFrom($estado);

        if ($accion === '' || $estadoEmisor === null || ! ctype_digit($version)) {
            return null;
        }

        return new self($accion, $estadoEmisor, (int) $version);
    }

    /**
     * ¿Este botón corresponde al paso en el que la conversación está ahora?
     *
     * Se compara contra el estado **y** la versión: los dos tienen que coincidir.
     */
    public function siguevigente(Conversation $conversacion): bool
    {
        return $this->estadoEmisor->value === $conversacion->current_state
            && $this->version === (int) $conversacion->state_version;
    }

    /** Longitud del `id` serializado, para verificar que entra en el campo. */
    public function longitud(): int
    {
        return strlen((string) $this);
    }
}
