<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Meta\MetaAdapter;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Recordatorios\PlantillaRecordatorio24h;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * T-039 · Los recordatorios que no salieron, y el reintento manual (US-24).
 *
 * ## Por qué el reintento tiene que ser manual
 *
 * `EnviarRecordatorios::tomarElCandado()` inserta la fila de `notification_logs`
 * **antes** de llamar a Meta, así que un recordatorio que falló al enviarse ya
 * consumió el único `(booking_id, type)` de T-009: **la corrida automática no lo
 * va a volver a intentar nunca.** Sin esta pantalla, ese cliente queda sin aviso
 * y nadie se entera.
 *
 * ⚠️ **No confundir con el recordatorio *frenado*.** La decisión § 2 de
 * `decisiones-tomadas.md` fijó que un freno previo al envío —plantilla sin
 * aprobar, cuenta sin dar de alta, Google que no contesta— **no toma el
 * candado**, justamente para que la corrida siguiente lo reintente sola. Son dos
 * caminos con el mismo síntoma y distinto arreglo.
 *
 * ## Por qué solo los turnos futuros
 *
 * Un recordatorio fallido de un turno que ya pasó es historia; uno de un turno
 * de mañana todavía se arregla llamando al cliente. La lista existe para lo
 * segundo, y mezclarlas la vuelve inútil por volumen a las pocas semanas.
 *
 * ## Dónde entra la zona del tenant (RNF-02)
 *
 * Solo en `cuando`, vía `HoraLocal`. El tiempo restante es una resta entre dos
 * instantes y no depende de la zona: un instante es el mismo en todas.
 */
class RecordatoriosFallidosController extends Controller
{
    /**
     * AC-24.4 · Ventana mínima hasta el turno para que reintentar sirva.
     *
     * Un mensaje que dice *"te recordamos tu turno de mañana"* mandado noventa
     * minutos antes confunde más de lo que ayuda: el cliente cree que el turno es
     * otro día. Es el mismo criterio por el que T-037 no manda recordatorios de
     * turnos creados con menos de 24 horas.
     */
    public const HORAS_MINIMAS = 2;

    /**
     * AC-24.2 · Los fallidos de turnos futuros, con turno, cliente y tiempo restante.
     */
    public function index(Request $request)
    {
        $tenant = $request->user()->tenant;
        $ahora = CarbonImmutable::now('UTC');

        return view('panel.recordatorios-fallidos', [
            'tenant' => $tenant,
            'usuario' => $request->user(),
            'fallidos' => $this->fallidos($tenant, $ahora),
            'horas_minimas' => self::HORAS_MINIMAS,
        ]);
    }

    /**
     * AC-24.3 · El reintento manual, y lo que pasó con él.
     *
     * *"Refleja el resultado"* incluye el resultado malo: un reintento que falla
     * en silencio y muestra "listo" es peor que no tener botón, porque el dueño
     * se queda tranquilo y el cliente igual no viene.
     */
    public function reintentar(Request $request, string $registro): RedirectResponse
    {
        $log = $this->delTenant($request, $registro);
        $tenant = $request->user()->tenant;
        $ahora = CarbonImmutable::now('UTC');

        $booking = Booking::query()->find($log->booking_id);

        if ($booking === null) {
            // RNF-03 · Ningún camino termina en silencio, ni este que no debería
            // poder ocurrir: la FK es `cascadeOnDelete`.
            return $this->conError($log, 'El turno de ese recordatorio ya no existe.', registrar: false);
        }

        if ($this->faltaMenosDeLaVentana($booking, $ahora)) {
            /*
             * AC-24.4 · No se manda **y no se toca el registro**: sigue fallido,
             * que es lo que es. Marcarlo de cualquier otra forma haría desaparecer
             * de la lista un turno que el dueño todavía puede salvar llamando.
             */
            return $this->conError($log, sprintf(
                'El turno de %s es en menos de %d horas: un «recordatorio de mañana» a esta altura confunde al cliente. Llamalo.',
                $booking->client_name,
                self::HORAS_MINIMAS,
            ), registrar: false);
        }

        $meta = $this->metaDe($tenant);
        $conversacion = Conversation::query()->find($booking->conversation_id);

        if ($meta === null || $conversacion === null) {
            return $this->conError(
                $log,
                'No se pudo reintentar: falta la conexión con WhatsApp o la conversación del cliente.',
            );
        }

        $wamid = $this->mandar($meta, $conversacion, $booking, $tenant);

        if ($wamid === null) {
            return $this->conError(
                $log,
                "WhatsApp volvió a rechazar el recordatorio de {$booking->client_name}. No le llegó.",
            );
        }

        /*
         * El reintento **actualiza la fila, no crea una segunda**: una segunda
         * chocaría contra el único `(booking_id, type)`, que es el candado
         * anti-duplicados de T-037. Se limpian `failure_reason` y `failed_at`
         * porque el registro dejó de ser un fallo y la lista se arma por `status`.
         */
        $log->forceFill([
            'whatsapp_message_id' => $wamid,
            'status' => NotificationLog::ESTADO_ENVIADO,
            'sent_at' => $ahora,
            'failure_reason' => null,
            'failed_at' => null,
        ])->save();

        return redirect()->route('panel.recordatorios-fallidos')->with(
            'exito',
            "Listo: el recordatorio de {$booking->client_name} salió.",
        );
    }

    /**
     * Los fallidos con turno todavía por delante.
     *
     * Dos consultas y no un join: el Global Scope de `BelongsToTenant` **no
     * aplica a un join manual**, y acá lo que se lista son nombres y teléfonos de
     * la clientela. Con dos consultas scopeadas el aislamiento lo garantiza el
     * modelo y no la memoria de quien escribe el SQL (RNF-01).
     *
     * @return array<int,array<string,mixed>>
     */
    private function fallidos(Tenant $tenant, CarbonImmutable $ahora): array
    {
        $registros = NotificationLog::query()
            ->where('status', NotificationLog::ESTADO_FALLIDO)
            ->get();

        if ($registros->isEmpty()) {
            return [];
        }

        $turnos = Booking::query()
            ->whereIn('id', $registros->pluck('booking_id')->all())
            ->where('start_time', '>', $ahora->format('Y-m-d H:i:s'))
            ->where('status', '!=', Booking::ESTADO_CANCELADO)
            ->get()
            ->keyBy('id');

        return $registros
            ->filter(fn (NotificationLog $l) => $turnos->has($l->booking_id))
            ->map(function (NotificationLog $log) use ($turnos, $tenant, $ahora) {
                $booking = $turnos->get($log->booking_id);
                $minutos = (int) $ahora->diffInMinutes($booking->start_time);

                return [
                    'notification_log_id' => $log->id,
                    'booking_id' => $booking->id,
                    'client_name' => $booking->client_name,
                    'client_phone' => $booking->client_phone,
                    'service_name' => $booking->service_name,
                    // RNF-02 · Al humano nunca se le muestra UTC.
                    'cuando' => HoraLocal::corta($booking->start_time, $tenant),
                    'tiempo_restante_minutos' => $minutos,
                    'tiempo_restante' => $this->legible($minutos),
                    'motivo' => (string) ($log->failure_reason ?: 'No quedó registrado el motivo.'),
                    'se_puede_reintentar' => $minutos > self::HORAS_MINIMAS * 60,
                ];
            })
            // El más urgente arriba: es el que todavía se puede salvar llamando.
            ->sortBy('tiempo_restante_minutos')
            ->values()
            ->all();
    }

    /** El envío, con el mismo cuerpo y la misma plantilla que la corrida automática. */
    private function mandar(Integration $meta, Conversation $conversacion, Booking $booking, Tenant $tenant): ?string
    {
        try {
            return (new MetaAdapter($meta))->enviarPlantilla(
                $conversacion,
                PlantillaRecordatorio24h::armar($booking, $tenant, $conversacion),
                PlantillaRecordatorio24h::textoParaElHistorial($booking, $tenant),
            );
        } catch (\Throwable $e) {
            /*
             * Un rate limit de Meta llega hasta acá como excepción. **No sube**:
             * el dueño apretó un botón y tiene que ver qué pasó, no un 500. Y a
             * diferencia de la cola, acá no hay reintento automático detrás.
             */
            Log::error('El reintento manual del recordatorio no se pudo enviar', [
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'integracion' => 'meta_whatsapp',
                'codigo' => 'RECORDATORIO_REINTENTO_FALLIDO',
                'excepcion' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Deja el registro fallido con motivo y hora, y vuelve con el mensaje.
     *
     * `$registrar` en `false` para los frenos que **no son un fallo de envío**:
     * el turno está muy cerca, o falta la conversación. Pisar `failure_reason`
     * ahí borraría el motivo real del fallo original, que es el que el dueño
     * necesita para saber si lo puede arreglar.
     */
    private function conError(NotificationLog $log, string $mensaje, bool $registrar = true): RedirectResponse
    {
        if ($registrar) {
            $log->forceFill([
                'status' => NotificationLog::ESTADO_FALLIDO,
                'sent_at' => null,
                'failure_reason' => $mensaje,
                'failed_at' => CarbonImmutable::now('UTC'),
            ])->save();
        }

        return redirect()->route('panel.recordatorios-fallidos')->with('error', $mensaje);
    }

    /** ¿El turno está adentro de la ventana en la que el recordatorio ya no sirve? */
    private function faltaMenosDeLaVentana(Booking $booking, CarbonImmutable $ahora): bool
    {
        // Comparación entre instantes: la zona no cambia el orden ni la distancia.
        return $booking->start_time->lessThanOrEqualTo($ahora->addHours(self::HORAS_MINIMAS));
    }

    /**
     * ⚠️ El emisor sale de la integración del tenant y **nunca** de
     * `config('services.meta.phone_number_id')`: con una variable global, el
     * reintento de la peluquería sale desde el número del consultorio.
     */
    private function metaDe(Tenant $tenant): ?Integration
    {
        return Integration::query()
            // `Integration` no lleva Global Scope: el filtro va explícito.
            ->where('tenant_id', $tenant->id)
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->first();
    }

    /** El tiempo restante para leer, no para calcular. */
    private function legible(int $minutos): string
    {
        if ($minutos < 60) {
            return "{$minutos} min";
        }

        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return $resto === 0 ? "{$horas} h" : "{$horas} h {$resto} min";
    }

    /**
     * El registro, si es de este tenant. Si no, 403 y queda el rastro.
     *
     * Se consulta **sin** el Global Scope a propósito, igual que en
     * `AsistenciaController`: con el scope, un registro ajeno devolvería 404 y
     * sería indistinguible de un id inexistente. AC-14.2 pide `403` y registro
     * del intento — un acceso cruzado sin rastro no se puede auditar.
     *
     * Reintentar es **mandar un WhatsApp**: un reintento cruzado le manda a un
     * cliente un mensaje desde el número equivocado con los datos de un turno de
     * otro negocio.
     */
    private function delTenant(Request $request, string $id): NotificationLog
    {
        $log = NotificationLog::withoutTenantScope()->find($id);

        if ($log === null) {
            abort(404);
        }

        /*
         * Comparación como **string**: los ids de tenant son UUID y un `(int)`
         * sobre un UUID devuelve `1` para todos, con lo que el chequeo pasaría
         * siempre y la fuga seguiría abierta sin que nada avise.
         */
        if ((string) $log->tenant_id !== (string) $request->user()->tenant_id) {
            Log::warning('Intento de reintentar el recordatorio de otro tenant', [
                'tenant_id' => $request->user()->tenant_id,
                'tenant_id_objetivo' => $log->tenant_id,
                'user_id' => $request->user()->id,
                'notification_log_id' => $log->id,
                'ruta' => $request->path(),
                'ip' => $request->ip(),
                'codigo' => 'AUTZ_TENANT_CRUZADO',
            ]);

            throw new AccessDeniedHttpException('Ese recordatorio no es de tu negocio.');
        }

        return $log;
    }
}
