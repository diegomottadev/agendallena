<?php

namespace App\Models;

use App\Casts\FechaUtc;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Conversación con un número de WhatsApp.
 *
 * **Es la fuente de verdad del estado** (decisión cerrada en RF-A3): Redis
 * `session:tenant:{id}:phone:{phone}` es caché de lectura y lock. Ante
 * divergencia, manda esta tabla.
 *
 * ⚠️ Las transiciones de estado son **T-018** y no viven acá. Este modelo existe
 * hoy porque T-048 necesita algo a lo que colgar los mensajes: la ingesta se
 * limita a garantizar que la fila exista, sin decidir ningún estado.
 */
class Conversation extends Model
{
    use BelongsToTenant;

    public const ESTADO_INICIAL = 'IDLE';

    protected $fillable = [
        'tenant_id',
        'user_phone',
        'user_name',
        'current_state',
        'state_version',
        'context_data',
        'last_interaction_at',
        // T-035 · La marca de derivación a una persona (AC-20.1).
        'handoff_reason',
        'handoff_at',
        'handoff_resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context_data' => 'array',
            'state_version' => 'integer',
            /*
             * `FechaUtc` y no `datetime`: el cast de Laravel formatea el Carbon
             * en la zona que traiga, asi que un instante en hora argentina se
             * guardaria como hora argentina. RNF-02 exige UTC siempre.
             */
            'last_interaction_at' => FechaUtc::class,
            // T-035 · Mismo motivo que arriba: la hora de la derivación se
            // guarda en UTC y se convierte recién al mostrarla en el panel.
            'handoff_at' => FechaUtc::class,
            'handoff_resolved_at' => FechaUtc::class,
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * T-047 · El turno de esta conversación, para el panel.
     *
     * Es `hasOne` y no `hasMany` porque el panel muestra **un** turno por fila:
     * el último. Una conversación puede acumular varios —cancelar y volver a
     * reservar deja la fila cancelada—, y el que importa es el más nuevo.
     *
     * Existir como relación es lo que permite resolverlo con `with()` en una
     * sola consulta: sin esto el listado consulta `bookings` una vez por fila.
     */
    public function turno(): HasOne
    {
        return $this->hasOne(Booking::class)->latestOfMany();
    }
}
