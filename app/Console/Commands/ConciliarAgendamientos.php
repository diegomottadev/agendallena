<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Integration;
use App\Models\Tenant;
use App\Services\Google\CalendarioDeGoogle;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * T-030 · AC-22.1 · La conciliación entre el calendario y `bookings`.
 *
 * **La decisión se cerró el 2026-08-20: conciliar, no compensar.** El evento no
 * se borra en el acto; una revisión periódica lo detecta y lo reporta.
 *
 * ## Qué busca
 *
 * Un evento **nuestro** en el calendario de la PyME que ninguna fila de
 * `bookings` reclama. Es lo que queda cuando el worker muere entre el `POST` a
 * Google y el `INSERT`, y también cuando el borrado compensatorio de
 * `Fallback::liberarEvento()` no funciona —Google contesta 500 y el evento
 * sobrevive—. Hoy eso termina en una línea `EVENTO_HUERFANO_PERSISTE` y nadie
 * vuelve a mirar.
 *
 * El estado que deja es el hueco H-04: el horario está bloqueado para todos los
 * demás por un turno que el sistema no conoce, no genera recordatorio y no se
 * puede cancelar desde el chat.
 *
 * ## Lo que **no** hace
 *
 * **No borra nada.** Reportar es el alcance completo de este ciclo: pasar de
 * reportar a limpiar es una decisión que nadie tomó, y borrarle al dueño algo de
 * su propio calendario es exactamente el error que la conciliación viene a
 * evitar. Por eso el filtro por `origen=agendallena` es la primera línea de
 * defensa: la agenda propia del dueño no es asunto nuestro.
 *
 * ## La dirección inversa (§ 9, cerrada el 2026-08-21)
 *
 * La misma corrida mira también **la fila viva cuyo evento ya no está en el
 * calendario**: el dueño lo borró a mano desde su propio Google Calendar,
 * seguramente porque el cliente lo llamó por teléfono. Para él ese turno no
 * existe; para nosotros sigue ocupando la agenda y **entra en la tasa de
 * ausentismo**, que es el número con el que se vende el producto. Ese turno
 * **se cancela**, y a diferencia del huérfano acá sí se escribe en la base.
 *
 * Ahí la asimetría manda: para el huérfano equivocarse cuesta una línea de log
 * de más, para la cancelación cuesta la agenda de una PyME. Por eso el barrido
 * inverso se apoya en el mismo listado que ya se pidió —nunca en un `GET` por
 * turno, que además no mira la marca `origen` y daría por vivo un evento que el
 * dueño cargó a mano— y **un calendario que no se pudo leer no cancela nada**.
 *
 * Corre desde el scheduler; ver `routes/console.php`.
 */
class ConciliarAgendamientos extends Command
{
    protected $signature = 'agendamientos:conciliar';

    protected $description = 'Reporta los eventos de Google sin fila y cancela los turnos cuyo evento ya no está';

    /**
     * ⚠️ **La ventana no la fija ningún documento.**
     *
     * Hacia adelante se mira lejos porque el daño de un huérfano es ocupar un
     * horario que todavía no llegó. Hacia atrás alcanza con poco: un huérfano
     * pasado ya no bloquea nada, y solo sirve para auditar.
     */
    public const DIAS_HACIA_ATRAS = 2;

    public const DIAS_HACIA_ADELANTE = 60;

    /**
     * § 7 · Cada cuánto corre la conciliación, en minutos.
     *
     * Vive acá y no en `routes/console.php` porque es política, no plomería: es
     * **cuánto tiempo un horario puede quedar ocupado sin turno real detrás**,
     * que es la contracara de haber elegido conciliar en vez de compensar. El
     * scheduler deriva su expresión de cron de este número, así que moverlo a 5
     * o a 60 si el piloto lo pide es una sola línea.
     */
    public const MINUTOS_ENTRE_CORRIDAS = 15;

    public function handle(CalendarioDeGoogle $calendario): int
    {
        $ahora = CarbonImmutable::now('UTC');

        $desde = $ahora->subDays(self::DIAS_HACIA_ATRAS);
        $hasta = $ahora->addDays(self::DIAS_HACIA_ADELANTE);

        $desalineados = 0;
        $cancelados = 0;
        $revisados = 0;

        /*
         * Consulta cross-tenant deliberada, como en `EnviarRecordatorios`: es
         * una tarea de plataforma y no corre dentro de la sesión de ningún
         * tenant. De acá en adelante **cada PyME se revisa dentro de su propio
         * `runAs()`**, para que el Global Scope filtre las filas por sí mismo
         * (RNF-01).
         */
        foreach (Tenant::query()->orderBy('created_at')->cursor() as $tenant) {
            $google = Integration::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->first();

            // Sin calendario conectado no hay nada que conciliar. No es un fallo:
            // es una PyME que todavía no terminó el alta.
            if ($google === null) {
                continue;
            }

            $revisados++;

            [$huerfanos, $caidos] = TenantContext::runAs(
                (string) $tenant->id,
                fn () => $this->conciliarTenant($calendario, $tenant, $google, $ahora, $desde, $hasta),
            );

            $desalineados += $huerfanos;
            $cancelados += $caidos;
        }

        $this->info("Desalineados reportados: {$desalineados} · turnos cancelados sin evento: "
            ."{$cancelados} · calendarios revisados: {$revisados}");

        return self::SUCCESS;
    }

    /**
     * Las dos direcciones de este tenant, con **un solo listado a Google**.
     *
     * @return array{0:int,1:int} Huérfanos reportados y turnos cancelados.
     */
    private function conciliarTenant(
        CalendarioDeGoogle $calendario,
        Tenant $tenant,
        Integration $google,
        CarbonImmutable $ahora,
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
    ): array {
        try {
            $eventos = $calendario->eventosPropios($google, $tenant, $desde, $hasta);
        } catch (\Throwable $e) {
            /*
             * RNF-03 · Integración vencida, token que no se pudo renovar, Google
             * caído. **No sube**: una PyME con el calendario desconectado no
             * puede dejar sin conciliar a las demás. La corrida siguiente la
             * vuelve a intentar, y mientras tanto queda registrado que su
             * calendario no se revisó.
             */
            Log::error('No se pudo conciliar el calendario de la PyME', [
                'tenant_id' => $tenant->id,
                'integracion' => 'google_calendar',
                'codigo' => 'CONCILIACION_CALENDARIO_INACCESIBLE',
                'excepcion' => $e::class,
            ]);

            return [0, 0];
        }

        /*
         * `null` es "no se pudo leer", y ya quedó registrado adentro. Un
         * calendario que no se pudo leer no autoriza a concluir que está sano —y
         * mucho menos a cancelar: media hora de Google contestando 500 le
         * borraría a la PyME los turnos de los dos meses siguientes en dos
         * corridas, con la confirmación ya en el celular de cada cliente.
         */
        if ($eventos === null) {
            return [0, 0];
        }

        /*
         * ⚠️ Se pregunta **antes** de recorrer nada, porque de acá en adelante no
         * se vuelve a llamar a Google y el flag es del último listado.
         */
        $truncado = $calendario->ultimoListadoTruncado();

        $reportados = 0;

        foreach ($eventos as $evento) {
            $eventId = (string) ($evento['id'] ?? '');

            if ($eventId === '') {
                continue;
            }

            if ($this->tieneSuFila($tenant, $eventId)) {
                continue;
            }

            /*
             * El reporte es una línea estructurada con el `codigo` propio, que es
             * lo que T-039 sabe leer. Lleva el tenant adentro: un desalineado sin
             * dueño no se puede resolver — hay que saber en qué calendario entrar.
             */
            Log::warning('Evento en el calendario sin turno en la base', [
                'tenant_id' => $tenant->id,
                'external_event_id' => $eventId,
                'integracion' => 'google_calendar',
                'codigo' => 'CONCILIACION_DESALINEADO',
                'inicio' => $evento['start']['dateTime'] ?? null,
            ]);

            $reportados++;
        }

        /*
         * El listado vino incompleto: `eventosPropios()` pagina hasta
         * `MAX_PAGINAS` y el calendario seguía teniendo páginas, o falló una
         * página intermedia. Para los huérfanos eso cuesta un reporte perdido y por eso
         * esa dirección **sí corrió** recién, con lo que se haya leído. Para el
         * barrido inverso cuesta turnos reales: lo que quedó en la página que no
         * se leyó es indistinguible de lo que no existe, y cancelaríamos turnos
         * vivos con la confirmación ya en el celular del cliente —cada quince
         * minutos, para siempre—. Es la misma regla que el 500 y que la ventana:
         * **no haber mirado no es haber verificado que no está.**
         *
         * El freno es de **este tenant**, no de la corrida: una PyME grande no
         * puede dejar sin barrer a las demás, y el síntoma sería *no pasa nada*.
         */
        if ($truncado) {
            /*
             * Código propio, distinto del `CONCILIACION_LISTADO_TRUNCADO` que
             * `eventosPropios()` ya escribió: aquel cuenta un hecho sobre la
             * lectura, este cuenta que a esta PyME **no se le está prestando el
             * barrido inverso**. Es lo accionable: si se repite corrida tras
             * corrida, o Google le viene fallando o su calendario no entra en
             * `CalendarioDeGoogle::MAX_PAGINAS` y hay que subir el tope.
             */
            Log::warning('El barrido inverso no corrió: el calendario se leyó a medias', [
                'tenant_id' => $tenant->id,
                'integracion' => 'google_calendar',
                'codigo' => 'CONCILIACION_INVERSA_OMITIDA',
            ]);

            return [$reportados, 0];
        }

        return [$reportados, $this->cancelarTurnosSinEvento($tenant, $eventos, $ahora, $hasta)];
    }

    /**
     * § 9 · Los turnos vivos cuyo evento ya no está en el calendario.
     *
     * Se cruzan contra **el mismo listado** que acaba de traer el barrido de
     * huérfanos, y no contra un `GET` por turno: con la cadencia en 15 minutos
     * eso multiplicaría las llamadas a Google por turno vivo y por PyME, y
     * además un `GET` por id no mira la marca `origen`, así que daría por vivo
     * un evento que el dueño cargó a mano.
     *
     * @param  array<int,array<string,mixed>>  $eventos  Lo que Google devolvió.
     * @return int Cuántos turnos se cancelaron.
     */
    private function cancelarTurnosSinEvento(
        Tenant $tenant,
        array $eventos,
        CarbonImmutable $ahora,
        CarbonImmutable $hasta,
    ): int {
        $enElCalendario = [];

        foreach ($eventos as $evento) {
            $enElCalendario[(string) ($evento['id'] ?? '')] = true;
        }

        $cancelados = 0;

        foreach ($this->turnosVivosEnLaVentana($tenant, $ahora, $hasta) as $turno) {
            if (isset($enElCalendario[(string) $turno->external_event_id])) {
                continue;
            }

            /*
             * Se toca **solo** `status`. `attendance` es ortogonal a propósito
             * (T-036): a un turno que el dueño borró de su calendario nadie lo
             * esperó, y marcarlo `no_show` haría que cada limpieza suya le suba
             * la tasa de ausentismo, que es el número con el que se vende.
             */
            $turno->status = Booking::ESTADO_CANCELADO;

            try {
                $turno->save();
            } catch (\Throwable $e) {
                /*
                 * RNF-03 · Un deadlock o una base que se cae un segundo **no
                 * puede llevarse puesta la corrida**: sin este `catch` la
                 * excepción sube hasta `handle()`, las PyMEs que faltaban se
                 * quedan sin conciliar y ni siquiera se imprime el resumen, así
                 * que el síntoma desde afuera es que no pasó nada.
                 *
                 * Se sigue **con el turno siguiente** y no con la PyME siguiente:
                 * el que no se pudo guardar es uno, y los demás de esta misma
                 * PyME siguen mereciendo su barrido. La corrida de dentro de
                 * quince minutos lo reintenta solo — el turno quedó vivo, que es
                 * el lado seguro del error.
                 */
                Log::error('No se pudo cancelar el turno cuyo evento ya no está', [
                    'tenant_id' => $tenant->id,
                    'external_event_id' => (string) $turno->external_event_id,
                    'integracion' => 'google_calendar',
                    'codigo' => 'CONCILIACION_CANCELACION_FALLIDA',
                    'excepcion' => $e::class,
                ]);

                continue;
            }

            // Código propio, distinto del huérfano: son dos situaciones que se
            // resuelven distinto y quien lea el log tiene que poder separarlas.
            // Va **después** del `save()`: reportar como cancelado un turno que
            // sigue vivo mandaría a auditar un cambio que nunca ocurrió.
            Log::warning('Turno vivo cuyo evento ya no está en el calendario', [
                'tenant_id' => $tenant->id,
                'external_event_id' => (string) $turno->external_event_id,
                'integracion' => 'google_calendar',
                'codigo' => 'CONCILIACION_TURNO_SIN_EVENTO',
                'inicio' => $turno->start_time?->toIso8601String(),
            ]);

            $cancelados++;
        }

        return $cancelados;
    }

    /**
     * Los turnos que el barrido inverso tiene derecho a mirar.
     *
     * Las tres condiciones acotan por separado y ninguna sobra:
     *
     * - **La misma ventana que el listado.** De un turno a 80 días la corrida no
     *   tiene ninguna información: no lo pidió. Sin este corte, cada pasada
     *   cancelaría todos los turnos lejanos por no haberlos consultado —los que
     *   la PyME agenda con más anticipación—.
     * - **`start_time` todavía futuro.** ⚠️ Decisión del team-lead del
     *   2026-08-21, **pendiente de que Diego la ratifique**: § 9 no lo dice. Los
     *   dos daños que § 9 nombra no aplican hacia atrás —un turno pasado no
     *   ocupa nada— y es peor que inútil: la tasa se calcula sobre los turnos
     *   marcados, así que cancelar hacia atrás saca turnos de la métrica en
     *   silencio. Borrar un evento viejo del calendario es higiene; borrar uno
     *   futuro es una decisión sobre un turno, y solo eso vale interpretar.
     * - **No cancelado ya.** Un turno cancelado no tiene evento *por definición*.
     *   Sin excluirlos, cada corrida repetiría todos los cancelados de la
     *   historia y el reporte dejaría de decir cuántos desalineados hubo.
     *
     * El `where('tenant_id')` va explícito además del Global Scope: una consulta
     * que trajera la fila de otra PyME la cruzaría contra el calendario
     * equivocado —donde obviamente no está— y le cancelaría un turno real
     * (RNF-01).
     *
     * @return Collection<int,Booking>
     */
    private function turnosVivosEnLaVentana(
        Tenant $tenant,
        CarbonImmutable $ahora,
        CarbonImmutable $hasta,
    ): Collection {
        return Booking::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', Booking::ESTADO_CANCELADO)
            // Los dos bordes en UTC (RNF-02), como los guarda el cast `FechaUtc`.
            ->where('start_time', '>', $ahora->format('Y-m-d H:i:s'))
            ->where('start_time', '<=', $hasta->format('Y-m-d H:i:s'))
            ->orderBy('start_time')
            ->get();
    }

    /**
     * ¿Hay una fila **de este tenant** que reclame el evento?
     *
     * El `where('tenant_id')` es explícito además del Global Scope, y no es
     * redundancia: si esta consulta encontrara la fila de otra PyME —Google no
     * garantiza `id` distintos entre cuentas—, daría por sano un huérfano y el
     * desalineado quedaría invisible **por una fuga entre tenants**.
     */
    private function tieneSuFila(Tenant $tenant, string $eventId): bool
    {
        return Booking::query()
            ->where('tenant_id', $tenant->id)
            ->where('external_event_id', $eventId)
            ->exists();
    }
}
