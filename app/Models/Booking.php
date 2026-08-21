<?php

namespace App\Models;

use App\Casts\FechaUtc;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un turno agendado.
 *
 * `start_time` y `end_time` van en **UTC** (RNF-02, AC-07.1) con el cast que lo
 * garantiza: el cast `datetime` de Laravel formatea en la zona que traiga el
 * `Carbon`, y un turno creado desde una hora local se guardaría corrido.
 */
class Booking extends Model
{
    use BelongsToTenant;

    public const ESTADO_AGENDADO = 'scheduled';

    public const ESTADO_CONFIRMADO = 'confirmed';

    public const ESTADO_CANCELADO = 'cancelled';

    /**
     * T-042 · El cliente movio este turno a otro horario.
     *
     * Es distinto de `cancelled` a proposito: un turno cancelado es un turno
     * perdido y uno re-agendado es un turno conservado. El argumento de venta
     * los cuenta distinto, y sin la distincion el ausentismo se atribuye mal.
     */
    public const ESTADO_REAGENDADO = 'rescheduled';

    public const ASISTENCIA_PENDIENTE = 'pending';

    public const ASISTENCIA_ASISTIO = 'attended';

    public const ASISTENCIA_AUSENTE = 'no_show';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'integration_id',
        'external_event_id',
        'client_name',
        'client_phone',
        'service_name',
        'quote_amount',
        'start_time',
        'end_time',
        'status',
        'attendance',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => FechaUtc::class,
            'end_time' => FechaUtc::class,
            'quote_amount' => 'decimal:2',
            'attendance_marked_at' => FechaUtc::class,
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
