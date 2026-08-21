<?php

namespace App\Models;

use App\Casts\FechaUtc;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * § 8 y § 9 · Un desalineado entre el calendario y la base que **una persona
 * tiene que resolver**. Decisión del 2026-08-21.
 *
 * Nace pendiente —`resolved_at` en `NULL`— desde `ConciliarAgendamientos`, y lo
 * cierra alguien desde `TurnosEnDudaController`: lo mantiene o lo cancela. El
 * turno **no se toca** al detectarlo: ni `status` ni `attendance`.
 *
 * ## El candado no vive acá
 *
 * `unique(tenant_id, type, booking_id)` está en el esquema y **este modelo no lo
 * repite en PHP**. Una comprobación acá dejaría abierta la ventana entre el
 * `SELECT` y el `INSERT` —dos workers conciliando a la vez la pasan los dos— y,
 * peor, daría la falsa sensación de que el candado está puesto. Es la lección de
 * `live_event_id` y de `lead_spreadsheets`.
 *
 * ⚠️ El único **retiene también estando resuelto**, que es lo contrario de
 * `live_event_id`. El porqué está entero en la migración: mantener no recrea el
 * evento en Google, así que el desalineado sigue ahí después de resolverlo.
 */
class ReconciliationFinding extends Model
{
    use BelongsToTenant;

    /**
     * § 9 · Un turno vivo cuyo evento ya no está en el calendario del dueño.
     *
     * Es el único tipo que hoy se detecta. El huérfano —evento en Google sin
     * fila— sigue siendo solo una línea de log: no tiene turno que mantener ni
     * cancelar, así que las dos acciones de la pantalla no le aplican.
     */
    public const TIPO_TURNO_SIN_EVENTO = 'booking_without_event';

    /**
     * El turno va igual: el dueño borró el evento por error o lo movió de
     * calendario.
     *
     * ⚠️ **No recrea nada en Google.** El turno queda vivo en la base y ausente
     * del calendario: la inconsistencia sigue, resuelta por decisión y no por
     * reparación.
     */
    public const RESOLUCION_MANTENIDO = 'kept';

    /** El dueño confirma lo que ya había hecho en su calendario: el turno no va. */
    public const RESOLUCION_CANCELADO = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'type',
        'detected_at',
    ];

    /**
     * Las tres columnas de la resolución quedan **fuera de `fillable`** a
     * propósito, igual que la auditoría de asistencia en T-036: se escriben en un
     * solo lugar —el controlador que resuelve— y con `forceFill`. Un `update()`
     * masivo no puede cerrar un hallazgo por accidente.
     */
    protected function casts(): array
    {
        return [
            'detected_at' => FechaUtc::class,
            'resolved_at' => FechaUtc::class,
        ];
    }

    /**
     * El turno en duda.
     *
     * El panel lo carga con `with()`: el nombre del cliente y la hora del turno
     * son lo que hace falta para decidir, y pedirlos fila por fila es una N+1
     * sobre la pantalla que más se mira cuando algo anda mal.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
