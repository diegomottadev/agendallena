<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * T-038 · Registro de asistencia y tasa de ausentismo (US-17).
 *
 * Es lo que convierte el MVP en un experimento: sin este dato se sabe cuántos
 * botones se tocaron, pero no si la gente fue.
 *
 * ## `status` y `attendance` son dos ejes, no uno
 *
 * Marcar la asistencia **nunca** toca `bookings.status` (T-036). Un turno puede
 * estar `confirmed` y `no_show` a la vez, y ese cruce es justamente AC-17.4: sin
 * él se observa que el ausentismo bajó pero no se le puede atribuir causa.
 *
 * ## Dónde entra la zona del tenant (RNF-02)
 *
 * En dos lugares y en ninguno más:
 *
 * 1. **El rango de AC-17.3.** «Del 1 al 31 de agosto» es agosto *del negocio*.
 *    Recortarlo en UTC mueve turnos de un mes a otro — en Bogotá, UTC-5, un
 *    turno de las 21:00 del 31 cae en el mes siguiente.
 * 2. **Lo que se muestra en pantalla**, vía `HoraLocal`.
 *
 * La comparación de «ya pasó» es entre instantes y no necesita zona: un instante
 * es el mismo en todas. El `now()` se toma igual en la zona del negocio para que
 * el código diga de quién es el reloj.
 */
class AsistenciaController extends Controller
{
    /** Ya vino. */
    private const RESPUESTA_CONFIRMADO = 'confirmado';

    /** Le llegó el recordatorio y no contestó. */
    private const RESPUESTA_SIN_RESPUESTA = 'sin_respuesta';

    /**
     * Nunca le mandamos recordatorio.
     *
     * Va separado de `sin_respuesta` a propósito: un turno al que no se le avisó
     * no es un cliente que ignoró el aviso, y mezclarlos ensucia el grupo de
     * control con el que se compara la tasa.
     */
    private const RESPUESTA_SIN_RECORDATORIO = 'sin_recordatorio';

    /**
     * AC-17.2 · Los pasados sin marcar, y —si vino un rango— AC-17.3 y AC-17.4.
     */
    public function index(Request $request)
    {
        $tenant = $request->user()->tenant;

        [$desde, $hasta] = $this->rango($request, $tenant);

        // Sin rango no hay nada que agregar: la pantalla es solo la lista de
        // pendientes. Es además lo que mantiene esa carga en una sola consulta.
        $delRango = $desde === null ? collect() : $this->turnosDelRango($desde, $hasta);

        return view('panel.asistencia', [
            'tenant' => $tenant,
            'usuario' => $request->user(),
            'pendientes' => $this->pendientes($tenant),
            'desde' => $request->query('desde'),
            'hasta' => $request->query('hasta'),
            'resumen' => $desde === null ? null : $this->resumen($delRango),
            'marcados' => $desde === null ? [] : $this->marcados($delRango, $tenant),
        ]);
    }

    /**
     * AC-17.1 · Un clic y el dato queda guardado.
     *
     * Sin pantalla de confirmación intermedia: la lista de pendientes es larga y
     * un paso extra por turno es lo que hace que el dueño deje de cargarla.
     */
    public function marcar(Request $request, string $booking): RedirectResponse
    {
        $datos = $request->validate([
            'asistencia' => ['required', Rule::in([
                Booking::ASISTENCIA_ASISTIO,
                Booking::ASISTENCIA_AUSENTE,
            ])],
        ]);

        $turno = $this->delTenant($request, $booking);

        if ($this->todaviaNoEmpezo($turno)) {
            /*
             * Nadie puede saber si alguien vino a un turno que no ocurrió. Se
             * rechaza con un mensaje y no con un 500: el caso llega por un
             * listado viejo abierto en otra pestaña, no por un ataque.
             */
            return redirect()->route('panel.asistencia')->with(
                'error',
                "El turno de {$turno->client_name} todavía no empezó: no se puede registrar si vino.",
            );
        }

        /*
         * `forceFill` porque las columnas de auditoría no son `fillable`: se
         * escriben acá y en ningún otro lado. `status` no se toca —ver arriba—.
         */
        $turno->forceFill([
            'attendance' => $datos['asistencia'],
            'attendance_marked_by' => $request->user()->id,
            'attendance_marked_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return redirect()->route('panel.asistencia')->with(
            'exito',
            $datos['asistencia'] === Booking::ASISTENCIA_ASISTIO
                ? "Anotado: {$turno->client_name} vino a su turno."
                : "Anotado: {$turno->client_name} no vino a su turno.",
        );
    }

    /**
     * AC-17.2 · Los pasados sin marcar, el más reciente primero.
     *
     * ⚠️ **La consulta está escrita para el índice `(tenant_id, start_time,
     * status)` de T-009**, que el Global Scope completa por la izquierda con el
     * `tenant_id`. El rango sobre `start_time` es lo que la vuelve selectiva: un
     * filtro por `attendance` —que no está en ningún índice— escanearía la tabla
     * entera de todas las PyMEs.
     *
     * Un turno `cancelled` no queda pendiente: el cliente avisó y el horario se
     * liberó, así que no hay asistencia que registrar. Dejarlo pidiendo atención
     * para siempre es la vía más rápida a que el dueño abandone la lista — y con
     * la lista, el dato.
     *
     * @return array<int,array<string,mixed>>
     */
    private function pendientes(Tenant $tenant): array
    {
        $ahora = CarbonImmutable::now($tenant->timezone)->utc();

        return Booking::query()
            ->where('start_time', '<=', $ahora->format('Y-m-d H:i:s'))
            ->where('status', '!=', Booking::ESTADO_CANCELADO)
            ->where('attendance', Booking::ASISTENCIA_PENDIENTE)
            ->orderByDesc('start_time')
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'client_name' => $b->client_name,
                'service_name' => $b->service_name,
                'client_phone' => $b->client_phone,
                // RNF-02 · Al humano nunca se le muestra UTC.
                'cuando' => HoraLocal::corta($b->start_time, $tenant),
            ])->all();
    }

    /**
     * Los turnos del rango, una sola vez: de acá salen el resumen y el listado.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,Booking>
     */
    private function turnosDelRango(CarbonImmutable $desde, CarbonImmutable $hasta)
    {
        return Booking::query()
            ->whereBetween('start_time', [
                $desde->format('Y-m-d H:i:s'),
                $hasta->format('Y-m-d H:i:s'),
            ])
            ->where('status', '!=', Booking::ESTADO_CANCELADO)
            ->orderBy('start_time')
            ->get();
    }

    /**
     * AC-17.3 · El KPI del lean canvas.
     *
     * ⚠️ **La tasa se calcula sobre los turnos marcados, no sobre todos los del
     * rango.** Un turno sin marcar no es una asistencia: es un dato que no
     * tenemos. Metido en el denominador, la tasa **baja cuando el dueño marca
     * menos** — o sea que el número que sostiene el precio del producto mejoraría
     * solo, que es la peor propiedad posible para una métrica.
     *
     * @param  \Illuminate\Support\Collection<int,Booking>  $turnos
     * @return array<string,mixed>
     */
    private function resumen($turnos): array
    {
        $asistidos = $turnos->where('attendance', Booking::ASISTENCIA_ASISTIO)->count();
        $ausentes = $turnos->where('attendance', Booking::ASISTENCIA_AUSENTE)->count();
        $marcados = $asistidos + $ausentes;

        return [
            'turnos' => $turnos->count(),
            'asistidos' => $asistidos,
            'ausentes' => $ausentes,
            'marcados' => $marcados,
            'tasa_ausentismo' => $marcados === 0 ? 0.0 : round($ausentes * 100 / $marcados, 1),
        ];
    }

    /**
     * AC-17.4 · Cada turno marcado, con lo que el cliente había contestado.
     *
     * ⚠️ **Son tres respuestas y el ticket pide cuatro.** `cancelado` y
     * `re-agendado` no son alcanzables con lo que hay guardado hoy:
     *
     * - Cancelar desde el recordatorio deja el turno `cancelled` y libera el
     *   horario (T-037, AC-10.3). Ese turno no se marca nunca, así que no es un
     *   «turno marcado» en el sentido de este criterio.
     * - Re-agendar deja el turno en `scheduled` —T-042 está diferido—, o sea
     *   **indistinguible de no haber contestado**. No hay columna que los separe.
     *
     * @param  \Illuminate\Support\Collection<int,Booking>  $turnos
     * @return array<int,array<string,mixed>>
     */
    private function marcados($turnos, Tenant $tenant): array
    {
        $marcados = $turnos
            ->where('attendance', '!=', Booking::ASISTENCIA_PENDIENTE)
            ->values();

        $conRecordatorio = $this->turnosConRecordatorio($marcados->pluck('id')->all());

        return $marcados->map(fn (Booking $b) => [
            'id' => $b->id,
            'client_name' => $b->client_name,
            'service_name' => $b->service_name,
            'attendance' => $b->attendance,
            'status' => $b->status,
            'cuando' => HoraLocal::corta($b->start_time, $tenant),
            'respuesta_recordatorio' => $this->respuestaAlRecordatorio($b, $conRecordatorio),
        ])->all();
    }

    /**
     * A qué turnos les **llegó** el recordatorio t-24h.
     *
     * ⚠️ T-039 · AC-24.1 · Los fallidos no cuentan. Antes entraba cualquier fila
     * de `notification_logs`, con lo que un cliente que nunca recibió nada
     * aparecía como *"le avisamos y nos ignoró"*: la tasa de ausentismo —que es
     * el número que sostiene el precio del producto— mezclaba al que ignoró el
     * aviso con al que nunca supo que tenía turno, y con eso medía otra cosa.
     *
     * El Global Scope de `NotificationLog` filtra por tenant: el registro de otra
     * PyME no puede entrar acá ni por accidente (RNF-01).
     *
     * @param  array<int,int>  $ids
     * @return array<int,true>
     */
    private function turnosConRecordatorio(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return NotificationLog::query()
            ->whereIn('booking_id', $ids)
            ->where('type', NotificationLog::TIPO_RECORDATORIO_24H)
            ->where('status', '!=', NotificationLog::ESTADO_FALLIDO)
            ->pluck('booking_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /** @param  array<int,true>  $conRecordatorio */
    private function respuestaAlRecordatorio(Booking $booking, array $conRecordatorio): string
    {
        if (! isset($conRecordatorio[$booking->id])) {
            return self::RESPUESTA_SIN_RECORDATORIO;
        }

        // T-037 escribe la confirmación en `bookings.status`, no en una columna
        // propia: el dato de AC-17.4 sale de ahí.
        return $booking->status === Booking::ESTADO_CONFIRMADO
            ? self::RESPUESTA_CONFIRMADO
            : self::RESPUESTA_SIN_RESPUESTA;
    }

    /**
     * El rango pedido, en instantes UTC. `[null, null]` si no vino completo.
     *
     * ⚠️ Los dos extremos se interpretan **en la zona del negocio**: el día
     * arranca y termina cuando arranca y termina allá, no en UTC.
     *
     * @return array{0:?CarbonImmutable,1:?CarbonImmutable}
     */
    private function rango(Request $request, Tenant $tenant): array
    {
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');

        if (! is_string($desde) || ! is_string($hasta) || $desde === '' || $hasta === '') {
            return [null, null];
        }

        try {
            return [
                CarbonImmutable::parse($desde, $tenant->timezone)->startOfDay()->utc(),
                CarbonImmutable::parse($hasta, $tenant->timezone)->endOfDay()->utc(),
            ];
        } catch (\Throwable) {
            // Una fecha ilegible en la URL no puede tumbar la pantalla de
            // pendientes, que es la que se usa todos los días.
            return [null, null];
        }
    }

    /** ¿Todavía no ocurrió? Comparación entre instantes: la zona no cambia el orden. */
    private function todaviaNoEmpezo(Booking $booking): bool
    {
        return $booking->start_time->greaterThan(CarbonImmutable::now('UTC'));
    }

    /**
     * El turno, si es de este tenant. Si no, 403 y queda el rastro.
     *
     * Se consulta **sin** el Global Scope a propósito, igual que en
     * `ConversacionesController`: con el scope, un turno ajeno devolvería 404 y
     * sería indistinguible de un id inexistente. AC-14.2 pide `403` y registro
     * del intento — un acceso cruzado sin rastro no se puede auditar.
     */
    private function delTenant(Request $request, string $id): Booking
    {
        $turno = Booking::withoutTenantScope()->find($id);

        if ($turno === null) {
            abort(404);
        }

        /*
         * Comparación como **string**: los ids de tenant son UUID y un `(int)`
         * sobre un UUID devuelve `1` para todos, con lo que el chequeo pasaría
         * siempre y la fuga seguiría abierta sin que nada avise.
         */
        if ((string) $turno->tenant_id !== (string) $request->user()->tenant_id) {
            Log::warning('Intento de marcar la asistencia de un turno de otro tenant', [
                'tenant_id' => $request->user()->tenant_id,
                'tenant_id_objetivo' => $turno->tenant_id,
                'user_id' => $request->user()->id,
                'booking_id' => $turno->id,
                'ruta' => $request->path(),
                'ip' => $request->ip(),
                'codigo' => 'AUTZ_TENANT_CRUZADO',
            ]);

            throw new AccessDeniedHttpException('Ese turno no es de tu negocio.');
        }

        return $turno;
    }
}
