<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ReconciliationFinding;
use App\Models\Tenant;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * § 8 y § 9 · Los turnos en duda, y la persona que los resuelve.
 * Decisión del 2026-08-21.
 *
 * `ConciliarAgendamientos` encuentra cada quince minutos los turnos vivos cuyo
 * evento ya no está en el Google Calendar del dueño y **ya no decide nada**:
 * deja un hallazgo pendiente. Esta pantalla es la otra mitad — sin ella el
 * desalineado es invisible para el negocio y la decisión no existe.
 *
 * ## Las dos acciones, y lo que ninguna hace
 *
 * - **Mantener**: el turno va igual. El dueño borró el evento por error o lo
 *   movió de calendario. ⚠️ **No recrea nada en Google**, y lo fija el encargo:
 *   el turno queda vivo en la base y ausente del calendario. La inconsistencia
 *   sigue, resuelta por decisión y no por reparación — y por eso el candado de
 *   `reconciliation_findings` retiene también estando resuelto.
 * - **Cancelar**: el dueño confirma lo que ya había hecho en su calendario.
 *
 * ⚠️ **Ninguna de las dos le manda nada al cliente.** Fuera de la ventana de
 * 24 h de Meta haría falta una plantilla aprobada que no existe, así que el
 * contacto lo hace una persona desde el número de atención humana
 * (`business_settings.human_phone`), que es un WhatsApp común: sin plantilla,
 * sin ventana y sin costo por envío.
 *
 * ⚠️ **`attendance` no se toca en ningún camino.** Es ortogonal a `status`
 * (T-036) y tiene su propia auditoría de quién la marcó: un turno que el dueño
 * borró de su calendario no es un cliente que no vino, nadie lo esperó.
 */
class TurnosEnDudaController extends Controller
{
    /** Resolver «mantener» desde el formulario. */
    private const ACCION_MANTENER = 'mantener';

    /** Resolver «cancelar» desde el formulario. */
    private const ACCION_CANCELAR = 'cancelar';

    /**
     * § 8 · La bandeja: **solo los pendientes**, el más viejo primero.
     *
     * Lo ya resuelto no se lista. La pantalla es una bandeja de trabajo y no un
     * historial: si lo resuelto se quedara, cada semana costaría más encontrar lo
     * que sí hay que hacer, y el dueño terminaría igual que si no hubiera
     * pantalla. El registro de quién decidió qué queda en la fila, que es donde
     * se audita.
     *
     * El más viejo primero porque es el que lleva más tiempo sin resolver, y el
     * daño de un turno en duda crece con lo cerca que está de ocurrir.
     */
    public function index(Request $request)
    {
        $tenant = $request->user()->tenant;

        return view('panel.turnos-en-duda', [
            'tenant' => $tenant,
            'usuario' => $request->user(),
            'hallazgos' => $this->pendientes($tenant),
        ]);
    }

    /**
     * Mantener o cancelar, dejando **quién y cuándo**.
     *
     * La auditoría es la misma que `attendance_marked_by` de T-036 y por el mismo
     * motivo: cuando dentro de dos semanas el cliente llegue y no haya turno, la
     * única forma de reconstruir qué pasó es saber quién decidió.
     */
    public function resolver(Request $request, string $hallazgo): RedirectResponse
    {
        $datos = $request->validate([
            'resolucion' => ['required', Rule::in([self::ACCION_MANTENER, self::ACCION_CANCELAR])],
        ]);

        $registro = $this->delTenant($request, $hallazgo);

        $turno = Booking::query()->find($registro->booking_id);

        if ($turno === null) {
            // RNF-03 · Ningún camino termina en silencio, ni este que no debería
            // poder ocurrir: la FK es `cascadeOnDelete`.
            return redirect()->route('panel.turnos-en-duda')->with(
                'error',
                'El turno de ese aviso ya no existe.',
            );
        }

        $cancela = $datos['resolucion'] === self::ACCION_CANCELAR;

        if ($cancela) {
            /*
             * Se toca **solo** `status`, y no se le avisa a nadie. El horario
             * vuelve a quedar libre: la columna generada `live_event_id` se pone
             * en `NULL` sola al cancelar, así que otro cliente puede reservarlo.
             */
            $turno->status = Booking::ESTADO_CANCELADO;
            $turno->save();
        }

        /*
         * `forceFill` porque las tres columnas de la resolución no son
         * `fillable`: se escriben acá y en ningún otro lado, igual que la
         * auditoría de asistencia.
         */
        $registro->forceFill([
            'resolved_at' => CarbonImmutable::now('UTC'),
            'resolved_by' => $request->user()->id,
            'resolution' => $cancela
                ? ReconciliationFinding::RESOLUCION_CANCELADO
                : ReconciliationFinding::RESOLUCION_MANTENIDO,
        ])->save();

        return redirect()->route('panel.turnos-en-duda')->with(
            'exito',
            $cancela
                ? "Listo: el turno de {$turno->client_name} quedó cancelado. Si hace falta avisarle, escribile desde el número de atención."
                : "Listo: el turno de {$turno->client_name} sigue en pie. Ojo que en tu Google Calendar no está: si lo querés ver ahí, cargalo a mano.",
        );
    }

    /**
     * Los hallazgos sin resolver de este tenant, con lo que hace falta decidir.
     *
     * El Global Scope de `BelongsToTenant` filtra por tenant: el turno en duda de
     * otra PyME no puede entrar acá ni por accidente, y cada fila lleva el nombre
     * del cliente y la hora de su turno — verlas es ver la clientela de otro
     * negocio (RNF-01).
     *
     * `with('booking')` porque el nombre y la hora salen del turno: pedirlos fila
     * por fila es una N+1 sobre la pantalla que más se mira cuando algo anda mal.
     *
     * @return array<int,array<string,mixed>>
     */
    private function pendientes(Tenant $tenant): array
    {
        return ReconciliationFinding::query()
            ->whereNull('resolved_at')
            ->with('booking')
            ->orderBy('detected_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ReconciliationFinding $h) => [
                'id' => $h->id,
                'type' => $h->type,
                'client_name' => $h->booking?->client_name,
                'client_phone' => $h->booking?->client_phone,
                'service_name' => $h->booking?->service_name,
                'status' => $h->booking?->status,
                // RNF-02 · Al humano nunca se le muestra UTC.
                'cuando' => $h->booking === null
                    ? null
                    : HoraLocal::corta($h->booking->start_time, $tenant),
                'detectado' => HoraLocal::corta($h->detected_at, $tenant),
            ])->all();
    }

    /**
     * El hallazgo, si es de este tenant. Si no, 403 y queda el rastro.
     *
     * Se consulta **sin** el Global Scope a propósito, igual que en
     * `AsistenciaController`: con el scope, un hallazgo ajeno devolvería 404 y
     * sería indistinguible de un id inexistente. AC-14.2 pide `403` y registro
     * del intento — un acceso cruzado sin rastro no se puede auditar.
     *
     * Y lo que se resuelve por acá **cancela un turno real**: un `cancelar`
     * cruzado le da de baja a otro negocio un turno que iba a ocurrir, y el
     * cliente llega igual porque nadie le avisa nada.
     */
    private function delTenant(Request $request, string $id): ReconciliationFinding
    {
        $hallazgo = ReconciliationFinding::withoutTenantScope()->find($id);

        if ($hallazgo === null) {
            abort(404);
        }

        /*
         * Comparación como **string**: los ids de tenant son UUID y un `(int)`
         * sobre un UUID devuelve `1` para todos, con lo que el chequeo pasaría
         * siempre y la fuga seguiría abierta sin que nada avise.
         */
        if ((string) $hallazgo->tenant_id !== (string) $request->user()->tenant_id) {
            Log::warning('Intento de resolver el turno en duda de otro tenant', [
                'tenant_id' => $request->user()->tenant_id,
                'tenant_id_objetivo' => $hallazgo->tenant_id,
                'user_id' => $request->user()->id,
                'reconciliation_finding_id' => $hallazgo->id,
                'ruta' => $request->path(),
                'ip' => $request->ip(),
                'codigo' => 'AUTZ_TENANT_CRUZADO',
            ]);

            throw new AccessDeniedHttpException('Ese aviso no es de tu negocio.');
        }

        return $hallazgo;
    }
}
