<?php

namespace App\Console\Commands;

use App\Meta\AltaDeCuenta;
use App\Meta\MetaAdapter;
use App\Meta\PlantillasDelTenant;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Recordatorios\PlantillaRecordatorio24h;
use App\Services\Google\CalendarioDeGoogle;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * T-037 · El recordatorio interactivo t-24h.
 *
 * **Es el único número del pitch que se traduce directo a plata.** El lean
 * canvas promete bajar el ausentismo de 20-35 % a menos del 8 %: si el
 * recordatorio no sale, o sale dos veces, o sale con la hora en UTC, esa cifra
 * no tiene con qué sostenerse.
 *
 * Corre desde el scheduler cada 10 minutos; ver `routes/console.php`.
 *
 * ## La ventana
 *
 * `t-24h ± 10 min`, que con la tarea corriendo cada 10 minutos garantiza que
 * ningún turno se cuele entre dos corridas. El corolario es que **la ventana
 * dura 20 minutos y dos corridas caen adentro**: sin candado, el cliente recibe
 * el mismo recordatorio dos veces y la PyME paga las dos plantillas. El candado
 * es el único `(booking_id, type)` de `notification_logs` (T-009), no una
 * comprobación en PHP.
 *
 * Un turno creado con menos de 24 horas de anticipación nunca entra en la
 * ventana, y es lo correcto: mandarle un «recordatorio de mañana» un rato antes
 * del turno es peor que el silencio (AC-10.4).
 */
class EnviarRecordatorios extends Command
{
    protected $signature = 'recordatorios:enviar';

    protected $description = 'Manda el recordatorio interactivo t-24h de los turnos que entran en la ventana';

    /** Anticipación del recordatorio, en horas (AC-10.1). */
    public const HORAS_DE_ANTICIPACION = 24;

    /** Tolerancia a cada lado de la ventana, en minutos (AC-10.1). */
    public const TOLERANCIA_MINUTOS = 10;

    public function handle(): int
    {
        /*
         * Aritmética con Carbon sobre instantes UTC. `t-24h` son 24 horas
         * absolutas antes del turno, no «la misma hora del día anterior»: la
         * segunda lectura cambiaría de significado el día del cambio estacional
         * y el recordatorio saldría corrido sin que nada avise. La zona del
         * tenant entra recién al redactar el texto.
         */
        $centro = CarbonImmutable::now('UTC')->addHours(self::HORAS_DE_ANTICIPACION);

        $desde = $centro->subMinutes(self::TOLERANCIA_MINUTOS);
        $hasta = $centro->addMinutes(self::TOLERANCIA_MINUTOS);

        /*
         * Consulta cross-tenant deliberada, como en `ExpirarConversacionesInactivas`:
         * es una tarea de plataforma y no corre dentro de la sesión de ningún
         * tenant, así que el Global Scope exigiría un tenant activo que no hay.
         * De acá en adelante **cada turno se procesa dentro de su propio
         * `runAs()`** (RNF-01).
         *
         * `status = scheduled` filtra los cancelados: recordarle un turno a
         * alguien que ya lo canceló es una llamada al negocio de un cliente
         * confundido. Es también el índice `(tenant_id, start_time, status)`.
         */
        $turnos = Booking::withoutTenantScope()
            ->where('status', Booking::ESTADO_AGENDADO)
            ->whereBetween('start_time', [
                $desde->format('Y-m-d H:i:s'),
                $hasta->format('Y-m-d H:i:s'),
            ])
            ->orderBy('start_time')
            ->get();

        $enviados = 0;

        foreach ($turnos as $booking) {
            $salio = TenantContext::runAs(
                (string) $booking->tenant_id,
                fn () => $this->recordar($booking),
            );

            $enviados += $salio ? 1 : 0;
        }

        $this->info("Recordatorios t-24h enviados: {$enviados} · turnos en ventana: {$turnos->count()}");

        return self::SUCCESS;
    }

    /**
     * @return bool  `true` solo si el cliente recibió el recordatorio.
     */
    private function recordar(Booking $booking): bool
    {
        $tenant = Tenant::find($booking->tenant_id);
        $conversacion = Conversation::find($booking->conversation_id);

        /*
         * ⚠️ El emisor sale de la integración del tenant y **nunca** de
         * `config('services.meta.phone_number_id')`: con una variable global, el
         * recordatorio de la peluquería le llega al cliente desde el número del
         * consultorio, y con un solo piloto no se nota.
         */
        $meta = Integration::query()
            ->where('tenant_id', $booking->tenant_id)
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->first();

        $google = Integration::query()
            ->where('tenant_id', $booking->tenant_id)
            ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
            ->first();

        if ($tenant === null || $conversacion === null || $meta === null || $google === null) {
            // RNF-03 · Un turno que no se puede recordar no puede desaparecer
            // sin dejar rastro: es lo que US-24 va a leer.
            Log::error('No se pudo mandar el recordatorio t-24h: falta con qué', [
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'codigo' => 'RECORDATORIO_SIN_INTEGRACION',
                'falta' => array_keys(array_filter([
                    'tenant' => $tenant === null,
                    'conversacion' => $conversacion === null,
                    'meta' => $meta === null,
                    'google' => $google === null,
                ])),
            ]);

            return false;
        }

        // T-050 · Con una WABA por PyME, las plantillas se aprueban **por
        // cuenta**: el cliente recién dado de alta las tiene creadas y Meta puede
        // tardar en aprobarlas, y eso no lo controlamos.
        //
        // El orden **no cambia si el recordatorio sale** —verificado invirtiendo
        // los dos frenos: la suite sigue verde— pero sí **qué se registra** de un
        // tenant al que le falta todo. Primero la cuenta, porque `waba_id` es la
        // causa raíz: sin cuenta nunca hubo alta, y por eso tampoco hay plantillas.
        // Al revés, el registro nombraría el síntoma y no lo que hay que cargar.
        if (! $this->tieneCuentaDeWhatsApp($booking, $meta)) {
            return false;
        }

        if (! $this->tienePlantillasAprobadas($booking, $meta)) {
            return false;
        }

        // Recorte Pareto · Se pregunta **antes** de mandar: consultarlo después
        // no evita nada, porque el mensaje ya salió.
        if (! $this->elEventoSigueVivo($booking, $google)) {
            return false;
        }

        $registro = $this->tomarElCandado($booking);

        if ($registro === null) {
            return false;
        }

        try {
            $wamid = (new MetaAdapter($meta))->enviarPlantilla(
                $conversacion,
                PlantillaRecordatorio24h::armar($booking, $tenant, $conversacion),
                PlantillaRecordatorio24h::textoParaElHistorial($booking, $tenant),
            );
        } catch (\Throwable $e) {
            /*
             * Un rate limit de Meta llega hasta acá como excepción. **No sube**:
             * un tenant no puede frenar los recordatorios de los demás. Y como la
             * corrida siguiente no lo va a reintentar —el candado ya está
             * tomado—, la fila en `failed` es todo lo que queda para saber que
             * este cliente no recibió su recordatorio (RNF-03).
             */
            $this->marcarFallido(
                $registro,
                'WhatsApp no aceptó el envío en el momento del recordatorio. Probá reintentarlo.',
            );

            Log::error('El recordatorio t-24h no se pudo enviar', [
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'integracion' => 'meta_whatsapp',
                'codigo' => 'RECORDATORIO_ENVIO_FALLIDO',
                'excepcion' => $e::class,
            ]);

            return false;
        }

        if ($wamid === null) {
            $this->marcarFallido($registro, 'WhatsApp rechazó el recordatorio: el mensaje no le llegó al cliente.');

            Log::error('Meta rechazó el recordatorio t-24h', [
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'integracion' => 'meta_whatsapp',
                'codigo' => 'RECORDATORIO_RECHAZADO',
            ]);

            return false;
        }

        /*
         * El `wamid` no es decorativo: es lo que hace resoluble el `context.id`
         * que Meta devuelve cuando el cliente toca un botón. Sin él, la respuesta
         * llega sin nada que la ate a este turno.
         */
        $registro->forceFill([
            'whatsapp_message_id' => $wamid,
            'status' => NotificationLog::ESTADO_ENVIADO,
            'sent_at' => now(),
        ])->save();

        return true;
    }

    /**
     * T-050 · criterio 5 · ¿A esta PyME le cargaron su cuenta de WhatsApp?
     *
     * Con una WABA por cliente, un tenant sin `waba_id` **no es un tenant
     * válido**: es uno a medio configurar, que nunca pasó por el alta y no tiene
     * ninguna plantilla aprobada a su nombre. Mandarle el recordatorio igual
     * termina en un rechazo de Meta que no dice qué falta, y el turno queda sin
     * aviso: es el fallo silencioso que RNF-03 prohíbe.
     *
     * Por eso se pregunta acá y no en `MetaAdapter`: el registro tiene que poder
     * nombrar **qué turno** quedó sin recordatorio, y el adapter no lo sabe.
     */
    private function tieneCuentaDeWhatsApp(Booking $booking, Integration $meta): bool
    {
        if (AltaDeCuenta::tieneCuenta($meta)) {
            return true;
        }

        Log::error('El recordatorio t-24h no salió: la PyME no tiene cargada su cuenta de WhatsApp', [
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'integracion' => 'meta_whatsapp',
            'codigo' => 'RECORDATORIO_SIN_CUENTA_DE_WHATSAPP',
            'falta' => 'waba_id',
        ]);

        return false;
    }

    /**
     * T-050 · criterio 4 · ¿Las plantillas **de esta PyME** ya están aprobadas?
     *
     * Mandar igual es tirar el recordatorio contra un `template not found`: el
     * turno queda sin avisar y nadie se entera hasta que el cliente no aparece,
     * que es justo el fallo silencioso que RNF-03 prohíbe. Por eso el freno se
     * registra, y con el turno y la PyME adentro.
     *
     * **No se toma el candado antes de frenar**, a propósito: si Meta aprueba
     * cinco minutos después, la corrida siguiente todavía encuentra el turno en
     * la ventana y el recordatorio sale. Consumir el único `(booking_id, type)`
     * acá dejaría ese turno sin recordatorio para siempre.
     *
     * ⚠️ **Un tenant sin ninguna plantilla registrada tampoco pasa.** Antes sí:
     * se preguntaba por las registradas-y-no-aprobadas, y una PyME a la que
     * nunca se le corrió el alta no tiene ninguna, así que la lista salía vacía
     * y se leía como "están todas aprobadas". Es la ventana que hay entre que
     * alguien carga la cuenta y alguien corre `cuenta:dar-de-alta`, y el envío
     * ahí es un `template not found` garantizado. **No saber nada de la cuenta
     * no es saber que está lista**, y el criterio 4 pide no intentar el envío.
     */
    private function tienePlantillasAprobadas(Booking $booking, Integration $meta): bool
    {
        if (PlantillasDelTenant::todasAprobadas($meta)) {
            return true;
        }

        $pendientes = PlantillasDelTenant::pendientes($meta);

        Log::warning('El recordatorio t-24h no salió: las plantillas del tenant no están aprobadas', [
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'integracion' => 'meta_whatsapp',
            'codigo' => 'RECORDATORIO_PLANTILLA_NO_APROBADA',
            'plantillas_pendientes' => $pendientes,
        ]);

        return false;
    }

    /**
     * El candado contra duplicados: el único `(booking_id, type)` de T-009.
     *
     * Se toma **antes** de mandar y no después. Comprobar con un `exists()` y
     * después insertar deja abierta toda la ventana entre las dos consultas: la
     * garantía tiene que ser la base, no el orden en que corran los procesos.
     *
     * @return NotificationLog|null  `null` si ya había registro: no se manda nada.
     */
    private function tomarElCandado(Booking $booking): ?NotificationLog
    {
        try {
            return NotificationLog::create([
                // Explícito y no solo por el contexto: la lista de turnos sin
                // avisar de una PyME no puede traer los de otra (RNF-01).
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'type' => NotificationLog::TIPO_RECORDATORIO_24H,
                'status' => NotificationLog::ESTADO_ENCOLADO,
                'sent_at' => null,
            ]);
        } catch (QueryException) {
            // Ya se mandó, o lo está mandando otra corrida. No es un error.
            return null;
        }
    }

    /**
     * T-039 · AC-24.1 · El registro queda fallido **con motivo y con hora**.
     *
     * Antes escribía solo `status = 'failed'`, y con eso quedaba una fila que
     * decía *"algo pasó"* y nada más: el dueño veía que el cliente no recibió el
     * recordatorio y no podía saber si fue un rechazo de Meta, un número mal
     * cargado o la cuenta suspendida — que son tres acciones distintas de su lado.
     *
     * ⚠️ `sent_at` se borra a propósito: es la columna de *cuándo salió*, y esto
     * nunca salió. La hora del fallo va en `failed_at`, que es otra pregunta.
     *
     * El turno **no queda marcado como recordado**: `AsistenciaController` no
     * cuenta las filas fallidas, así que el cliente que nunca supo de su turno no
     * se mezcla con el que nos ignoró.
     */
    private function marcarFallido(NotificationLog $registro, string $motivo): void
    {
        $registro->forceFill([
            'status' => NotificationLog::ESTADO_FALLIDO,
            'sent_at' => null,
            'failure_reason' => $motivo,
            'failed_at' => now(),
        ])->save();
    }

    private function elEventoSigueVivo(Booking $booking, Integration $google): bool
    {
        $eventId = trim((string) $booking->external_event_id);

        if ($eventId === '') {
            Log::error('Turno sin evento de Google: no se puede verificar antes del recordatorio', [
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'codigo' => 'RECORDATORIO_SIN_EVENTO',
            ]);

            return false;
        }

        try {
            return app(CalendarioDeGoogle::class)->eventoSigueExistiendo($google, $eventId);
        } catch (\Throwable $e) {
            /*
             * Integración vencida, token que no se pudo renovar, Google caído. No
             * se manda: no sabemos si el turno sigue en pie. **No se toma el
             * candado**, así que la corrida siguiente lo reintenta mientras la
             * ventana siga abierta.
             */
            Log::error('No se pudo verificar el evento en Google antes del recordatorio', [
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'integracion' => 'google_calendar',
                'codigo' => 'RECORDATORIO_VERIFICACION_FALLIDA',
                'excepcion' => $e::class,
            ]);

            return false;
        }
    }
}
