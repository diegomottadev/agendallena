<?php

namespace App\Models;

use App\Casts\FechaUtc;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué se le mandó al cliente por cada turno, y si salió.
 *
 * ## Por qué una tabla y no una línea de log
 *
 * Una confirmación que no se entrega deja al cliente sin saber que tiene turno,
 * y a la PyME con el horario bloqueado por alguien que no va a ir. La pregunta
 * que hay que poder hacer entonces es **"¿qué turnos quedaron sin avisar?"**, y
 * eso es una consulta por `booking_id`, no un `grep`. El único
 * `(booking_id, type)` de T-009 es lo que ata el registro al turno.
 *
 * `sent_at` lleva `FechaUtc` como toda `datetime` del proyecto: el cast
 * `datetime` de Laravel guardaría la hora en la zona que traiga el Carbon y la
 * fila quedaría corrida sin que nada avise (RNF-02).
 */
class NotificationLog extends Model
{
    use BelongsToTenant;

    public const TIPO_CONFIRMACION = 'confirmation';

    public const TIPO_RECORDATORIO_24H = 'reminder_24h';

    public const TIPO_RECORDATORIO_2H = 'reminder_2h';

    public const ESTADO_ENCOLADO = 'queued';

    public const ESTADO_ENVIADO = 'sent';

    /**
     * T-039 · Los dos estados que solo llegan por webhook de Meta.
     *
     * `sent` quiere decir *"Meta acepto el mensaje"*, no *"al cliente le
     * llego"*: un numero mal cargado o un telefono bloqueado dan `sent` igual.
     * Recien `delivered` dice que llego y `read` que lo abrieron.
     */
    public const ESTADO_ENTREGADO = 'delivered';

    public const ESTADO_LEIDO = 'read';

    public const ESTADO_FALLIDO = 'failed';

    /**
     * Los cuatro estados que manda Meta por webhook, y los unicos que se copian
     * a `status` desde la ingesta. `queued` es nuestro y Meta no lo conoce.
     *
     * @var array<int,string>
     */
    public const ESTADOS_DE_META = [
        self::ESTADO_ENVIADO,
        self::ESTADO_ENTREGADO,
        self::ESTADO_LEIDO,
        self::ESTADO_FALLIDO,
    ];

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'type',
        'whatsapp_message_id',
        'status',
        'sent_at',
        'failure_reason',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => FechaUtc::class,
            'failed_at' => FechaUtc::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
