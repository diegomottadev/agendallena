<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T-048 · Un mensaje del historial, entrante o saliente.
 *
 * ⚠️ **Guarda contenido de conversaciones de terceros:** el cliente final de la
 * PyME no es usuario nuestro. Retención definida en 12 meses (ver la migración);
 * el purgado todavía no está implementado.
 */
class Message extends Model
{
    use BelongsToTenant;

    public const ENTRANTE = 'inbound';

    public const SALIENTE = 'outbound';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'direction',
        'type',
        'content',
        'payload',
        'whatsapp_message_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Texto plano de un mensaje de Meta, cuando lo tiene.
     *
     * Los interactivos no traen `text.body`: el título del botón o de la fila
     * elegida es lo más parecido a "lo que dijo el cliente", y guardarlo hace
     * que el historial se lea como una conversación y no como una lista de
     * eventos sin contenido.
     *
     * @param  array<string,mixed>  $mensaje
     */
    public static function textoDe(array $mensaje): ?string
    {
        return $mensaje['text']['body']
            ?? $mensaje['interactive']['button_reply']['title']
            ?? $mensaje['interactive']['list_reply']['title']
            ?? $mensaje['button']['text']
            ?? null;
    }
}
