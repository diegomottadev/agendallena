<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Integration;
use App\Models\ReconciliationFinding;
use App\Models\Tenant;
use App\Services\Google\CalendarioDeGoogle;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * ausentismo**, que es el número con el que se vende el producto.
 *
 * ## 🔄 Ese turno **queda en duda, no se cancela** (2026-08-21)
 *
 * Antes esta corrida lo cancelaba sola. Cancelar en silencio deja al cliente sin
 * enterarse —tiene la confirmación en el celular y llega a la puerta—, y
 * avisarle automáticamente choca con la ventana de 24 h de Meta, que exige una
 * plantilla aprobada que no existe. Ahora la corrida **no decide**: escribe un
 * hallazgo pendiente en `reconciliation_findings` y lo resuelve una persona
 * desde `/panel/turnos-en-duda` —mantener o cancelar—, que si hace falta llama
 * al cliente desde el número de atención humana. **El cliente no recibe nada
 * automático.**
 *
 * Ahí la asimetría manda: para el huérfano equivocarse cuesta una línea de log
 * de más, para el hallazgo cuesta una fila que alguien tiene que atender a mano
 * y que el candado no vuelve a ofrecer. Por eso el barrido inverso se apoya en
 * el mismo listado que ya se pidió —nunca en un `GET` por turno, que además no
 * mira la marca `origen` y daría por vivo un evento que el dueño cargó a mano— y
 * **un calendario que no se pudo leer no deja en duda nada**.
 *
 * Corre desde el scheduler; ver `routes/console.php`.
 */
class ConciliarAgendamientos extends Command
{
    protected $signature = 'agendamientos:conciliar';

    protected $description = 'Reporta los eventos de Google sin fila y deja en duda los turnos cuyo evento ya no está';

    /**
     * Turnos que la corrida volvió a ver en duda y ya tenían su hallazgo.
     *
     * Es el caso normal —el desalineado no se arregla solo— y por eso no se
     * registra fila por fila: se cuenta acá y sale en el resumen, para que una
     * corrida que no encontró nada nuevo no se lea como una corrida que no hizo
     * nada (RNF-03).
     */
    private int $yaEstabanEnDuda = 0;

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

    /**
     * Cuántas PyMEs se resuelven de una vez.
     *
     * El `cursor()` que había acá traía los tenants de a uno y le preguntaba a
     * `integrations` en qué calendario entrar, una vez por PyME y cada quince
     * minutos. Se resuelve por lote y no sobre la cartera entera: el recorrido no
     * tiene ventana que lo acote —son todas las PyMEs—, así que el lote es lo que
     * impide cambiar un problema de consultas por uno de memoria.
     */
    private const PYMES_POR_LOTE = 200;

    /**
     * Cuántos ids de evento entran en un `whereIn` al cruzar contra `bookings`.
     *
     * Antes se preguntaba **uno por evento**: dos consultas con un evento, once
     * con diez, y el multiplicador no es la cantidad de PyMEs sino la cantidad de
     * turnos que la PyME agendó — crece con el éxito del producto. El corte existe
     * porque el listado de Google puede traer cientos de eventos y un `whereIn` de
     * ese tamaño deja de ser una consulta y pasa a ser un problema.
     */
    private const EVENTOS_POR_CONSULTA = 500;

    public function handle(CalendarioDeGoogle $calendario): int
    {
        $ahora = CarbonImmutable::now('UTC');

        $desde = $ahora->subDays(self::DIAS_HACIA_ATRAS);
        $hasta = $ahora->addDays(self::DIAS_HACIA_ADELANTE);

        $desalineados = 0;
        $enDuda = 0;
        $revisados = 0;

        /*
         * Consulta cross-tenant deliberada, como en `EnviarRecordatorios`: es
         * una tarea de plataforma y no corre dentro de la sesión de ningún
         * tenant. De acá en adelante **cada PyME se revisa dentro de su propio
         * `runAs()`**, para que el Global Scope filtre las filas por sí mismo
         * (RNF-01).
         *
         * El `id` de desempate lo pide el recorrido por lotes: sin un orden total,
         * dos altas del mismo segundo pueden caer en dos lotes o en ninguno.
         */
        $recorrido = Tenant::query()->orderBy('created_at')->orderBy('id');

        $recorrido->chunk(self::PYMES_POR_LOTE, function ($tenants) use (
            $calendario, $ahora, $desde, $hasta, &$desalineados, &$enDuda, &$revisados
        ): void {
            $calendarios = $this->calendariosDe($tenants);

            foreach ($tenants as $tenant) {
                $google = $calendarios[(string) $tenant->id] ?? null;

                // Sin calendario conectado no hay nada que conciliar. No es un fallo:
                // es una PyME que todavía no terminó el alta.
                if ($google === null) {
                    continue;
                }

                $revisados++;

                [$huerfanos, $dudosos] = TenantContext::runAs(
                    (string) $tenant->id,
                    fn () => $this->conciliarTenant($calendario, $tenant, $google, $ahora, $desde, $hasta),
                );

                $desalineados += $huerfanos;
                $enDuda += $dudosos;
            }
        });

        $this->info("Desalineados reportados: {$desalineados} · turnos que quedaron en duda: "
            ."{$enDuda} · ya estaban en duda: {$this->yaEstabanEnDuda} · calendarios revisados: {$revisados}");

        return self::SUCCESS;
    }

    /**
     * El calendario conectado de cada PyME del lote, en una sola consulta.
     *
     * ⚠️ Lectura cross-tenant, como el recorrido que la envuelve. El aislamiento
     * lo da el mapa: se indexa por `tenant_id` —UUID, comparado como string— y
     * cada PyME solo alcanza su propia entrada. Entrar al calendario equivocado
     * sería reportar como huérfano todo lo de otra cartera y, peor, dejarle en
     * duda turnos vivos que están sanos (RNF-01).
     *
     * @param  Collection<int,Tenant>  $tenants
     * @return array<string,Integration>
     */
    private function calendariosDe(Collection $tenants): array
    {
        $ids = $tenants->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        if ($ids === []) {
            return [];
        }

        $calendarios = [];

        $filas = Integration::query()
            ->whereIn('tenant_id', $ids)
            ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
            // El `first()` que esto reemplaza no tenía orden: se fija uno para que
            // la PyME con dos calendarios conectados elija siempre el mismo.
            ->orderBy('id')
            ->get();

        foreach ($filas as $integracion) {
            $calendarios[(string) $integracion->tenant_id] ??= $integracion;
        }

        return $calendarios;
    }

    /**
     * Las dos direcciones de este tenant, con **un solo listado a Google**.
     *
     * @return array{0:int,1:int} Huérfanos reportados y turnos que quedaron en duda.
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
         * mucho menos a dejar turnos en duda: media hora de Google contestando
         * 500 le llenaría la pantalla a la PyME con los turnos de los dos meses
         * siguientes, en dos corridas y sin que nadie toque nada. Y el candado lo
         * empeora: esos hallazgos son uno por turno y para siempre, así que hay
         * que resolverlos a mano uno por uno.
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

        /*
         * Qué eventos ya tienen su fila, preguntado **una vez por listado** y no
         * una vez por evento. Es el mismo patrón que esta clase ya usa del lado de
         * Google —cruzar contra lo que se trajo en vez de pedir de a uno— y el que
         * `AsistenciaController::turnosConRecordatorio()` usa del lado de la base:
         * un `whereIn` y un mapa en memoria.
         */
        $conFila = $this->filasDeEsosEventos($tenant, $eventos);

        foreach ($eventos as $evento) {
            $eventId = (string) ($evento['id'] ?? '');

            if ($eventId === '') {
                continue;
            }

            if (isset($conFila[$eventId])) {
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
         * se leyó es indistinguible de lo que no existe, y dejaríamos en duda la
         * agenda entera de esa PyME —una lista de basura que nadie va a poder
         * distinguir de la real, porque el candado no la vuelve a ofrecer—. Es la
         * misma regla que el 500 y que la ventana:
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

        return [$reportados, $this->dejarEnDudaLosTurnosSinEvento($tenant, $eventos, $ahora, $hasta)];
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
     * ## El turno **no se toca**: queda en duda
     *
     * Decisión del 2026-08-21. Antes esta misma función lo cancelaba, y el
     * cliente no se enteraba: tiene la confirmación en el celular y llega a la
     * puerta. Avisarle automáticamente tampoco se puede —fuera de las 24 h de
     * Meta hace falta una plantilla aprobada que no existe—, así que la corrida
     * deja de decidir: escribe un hallazgo pendiente y lo resuelve una persona
     * desde el panel, que si hace falta llama al cliente desde el número de
     * atención humana.
     *
     * Ni `status` ni `attendance` se tocan acá.
     *
     * @param  array<int,array<string,mixed>>  $eventos  Lo que Google devolvió.
     * @return int Cuántos turnos quedaron en duda por primera vez.
     */
    private function dejarEnDudaLosTurnosSinEvento(
        Tenant $tenant,
        array $eventos,
        CarbonImmutable $ahora,
        CarbonImmutable $hasta,
    ): int {
        $enElCalendario = [];

        foreach ($eventos as $evento) {
            $enElCalendario[(string) ($evento['id'] ?? '')] = true;
        }

        $enDuda = 0;

        foreach ($this->turnosVivosEnLaVentana($tenant, $ahora, $hasta) as $turno) {
            if (isset($enElCalendario[(string) $turno->external_event_id])) {
                continue;
            }

            if ($this->dejarEnDuda($tenant, $turno)) {
                $enDuda++;
            }
        }

        return $enDuda;
    }

    /**
     * Un hallazgo pendiente por ese turno. `false` si ya estaba en duda o falló.
     *
     * ## Se escribe **por el modelo** y sin `SELECT` previo
     *
     * Por el modelo —y no con un `insertOrIgnore()` del query builder ni con un
     * `insert()` masivo— porque esos caminos no disparan los eventos de Eloquent,
     * y con ellos se pierde el `creating` que pone el `tenant_id` del contexto
     * (RNF-01).
     *
     * Y sin preguntar antes: *"fijate si ya hay un hallazgo de este turno"* deja
     * abierta la ventana entre el `SELECT` y el `INSERT`, y dos workers que
     * concilian a la vez la pasan los dos. **El duplicado lo rechaza el único
     * `(tenant_id, type, booking_id)`**, y ese rechazo es el camino normal: la
     * corrida ve el mismo desalineado cada quince minutos porque no se arregla
     * solo — ni siquiera cuando alguien resuelve *mantener*, que no recrea nada
     * en Google.
     */
    private function dejarEnDuda(Tenant $tenant, Booking $turno): bool
    {
        try {
            ReconciliationFinding::create([
                'tenant_id' => $tenant->id,
                'booking_id' => $turno->id,
                'type' => ReconciliationFinding::TIPO_TURNO_SIN_EVENTO,
                'detected_at' => CarbonImmutable::now('UTC'),
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * El candado hizo su trabajo: ese turno ya está en duda —o ya lo
             * resolvió alguien, que retiene el único igual—. No es un error y no
             * se registra: son 96 corridas por día y la línea diría siempre lo
             * mismo. Se cuenta en el resumen del comando, que es donde el
             * operador mira cuánto trabajo hizo la pasada.
             */
            $this->yaEstabanEnDuda++;

            return false;
        } catch (\Throwable $e) {
            /*
             * RNF-03 · Un deadlock o una base que se cae un segundo **no puede
             * llevarse puesta la corrida**: sin este `catch` la excepción sube
             * hasta `handle()`, las PyMEs que faltaban se quedan sin conciliar y
             * ni siquiera se imprime el resumen, así que el síntoma desde afuera
             * es que no pasó nada.
             *
             * Se sigue **con el turno siguiente** y no con la PyME siguiente: el
             * que no se pudo guardar es uno, y los demás de esta misma PyME
             * siguen mereciendo su barrido. La corrida de dentro de quince
             * minutos lo reintenta sola.
             */
            Log::error('No se pudo dejar en duda el turno cuyo evento ya no está', [
                'tenant_id' => $tenant->id,
                'external_event_id' => (string) $turno->external_event_id,
                'integracion' => 'google_calendar',
                'codigo' => 'CONCILIACION_HALLAZGO_FALLIDO',
                'excepcion' => $e::class,
            ]);

            return false;
        }

        /*
         * Código propio, distinto del huérfano: son dos situaciones que se
         * resuelven distinto y quien lea el log tiene que poder separarlas.
         *
         * Va **después** del `create()` y solo cuando la fila entró: es el rastro
         * de que la corrida detectó un desalineado nuevo. Escribirlo también en
         * el duplicado convertiría cada turno en duda sin resolver en 96 líneas
         * diarias idénticas, y el reporte dejaría de servir para contar cuántos
         * desalineados hubo.
         */
        Log::warning('Turno vivo cuyo evento ya no está en el calendario', [
            'tenant_id' => $tenant->id,
            'external_event_id' => (string) $turno->external_event_id,
            'integracion' => 'google_calendar',
            'codigo' => 'CONCILIACION_TURNO_SIN_EVENTO',
            'inicio' => $turno->start_time?->toIso8601String(),
        ]);

        return true;
    }

    /**
     * Los turnos que el barrido inverso tiene derecho a mirar.
     *
     * Las tres condiciones acotan por separado y ninguna sobra:
     *
     * - **La misma ventana que el listado.** De un turno a 80 días la corrida no
     *   tiene ninguna información: no lo pidió. Sin este corte, cada pasada
     *   dejaría en duda todos los turnos lejanos por no haberlos consultado —los
     *   que la PyME agenda con más anticipación—.
     * - **`start_time` todavía futuro.** ⚠️ Decisión del team-lead del
     *   2026-08-21, **pendiente de que Diego la ratifique**: § 9 no lo dice. Los
     *   dos daños que § 9 nombra no aplican hacia atrás —un turno pasado no
     *   ocupa nada— y llenar la pantalla de turnos viejos que el dueño ya
     *   limpió es la vía más rápida a que deje de mirarla. Borrar un evento
     *   viejo del calendario es higiene; borrar uno futuro es una decisión sobre
     *   un turno, y solo eso vale interpretar.
     * - **No cancelado ya.** Un turno cancelado no tiene evento *por definición*.
     *   Sin excluirlos, cada corrida repetiría todos los cancelados de la
     *   historia y el reporte dejaría de decir cuántos desalineados hubo.
     *
     * El `where('tenant_id')` va explícito además del Global Scope: una consulta
     * que trajera la fila de otra PyME la cruzaría contra el calendario
     * equivocado —donde obviamente no está— y le dejaría en duda un turno sano,
     * con el nombre y el teléfono de esa clienta a la vista del otro negocio
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
     * Cuáles de esos eventos **ya tienen una fila de este tenant** que los reclame.
     *
     * El `where('tenant_id')` es explícito además del Global Scope, y no es
     * redundancia: si esta consulta encontrara la fila de otra PyME —Google no
     * garantiza `id` distintos entre cuentas—, daría por sano un huérfano y el
     * desalineado quedaría invisible **por una fuga entre tenants**.
     *
     * Se parte en tandas porque el listado de una PyME con la agenda llena puede
     * traer cientos de eventos: el costo deja de crecer por evento sin volverse un
     * `whereIn` sin techo.
     *
     * @param  array<int,array<string,mixed>>  $eventos
     * @return array<string,true>
     */
    private function filasDeEsosEventos(Tenant $tenant, array $eventos): array
    {
        $ids = [];

        foreach ($eventos as $evento) {
            $eventId = (string) ($evento['id'] ?? '');

            if ($eventId !== '') {
                $ids[$eventId] = true;
            }
        }

        $conFila = [];

        foreach (array_chunk(array_keys($ids), self::EVENTOS_POR_CONSULTA) as $tanda) {
            $encontrados = Booking::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('external_event_id', $tanda)
                ->pluck('external_event_id');

            foreach ($encontrados as $eventId) {
                $conFila[(string) $eventId] = true;
            }
        }

        return $conFila;
    }
}
