<?php

namespace App\Http\Controllers\Panel;

use App\Conversacion\Derivacion;
use App\Conversacion\Estado;
use App\Conversacion\MotivoDeDerivacion;
use App\Conversacion\Pausa;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EnlaceDeCalendario;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * T-047 · Listado de conversaciones · T-049 · Detalle con historial.
 * T-025 · Pausar y reactivar el bot desde el panel.
 *
 * ## Qué hace esta pantalla
 *
 * El listado paginado de conversaciones del tenant, con su estado, el turno y
 * el monto cotizado por fila (AC-14.1), el enlace al evento en Google Calendar
 * (AC-14.3) y la marca de intervención humana (AC-14.4). Desde ahí se abre el
 * detalle, que es el historial completo de mensajes (T-049).
 *
 * Y encima de eso, las dos acciones que ya vivían acá: pausar/reactivar el bot
 * (T-025) y resolver una derivación (T-035).
 *
 * ## Absorbió la pantalla mínima de T-025, no la duplicó
 *
 * Hasta T-047 esta misma URL era una pantalla mínima propia de T-025: un límite
 * fijo de 50 conversaciones, sin paginado y sin datos de turno. Eso era **deuda
 * declarada** por el recorte Pareto, no una pantalla aparte, y T-047 vino a
 * absorberla: con 300 conversaciones, 250 eran invisibles para el dueño y no
 * había forma de llegar a ellas.
 *
 * Por eso el listado sigue viviendo en `/panel/conversaciones` y los botones de
 * pausa y de derivación siguen donde estaban. Dos listados de conversaciones
 * compitiendo hubiera sido peor que no hacer nada.
 *
 * ## La mecánica de la pausa ya estaba
 *
 * `App\Conversacion\Pausa` (T-018b) escribe la llave `pause:tenant:{id}:phone:{phone}`
 * en Redis con TTL, y `ProcessMessageJob` la consulta antes de responder. Este
 * controlador **no agrega estado**: solo le pone manos a esa mecánica y le dice
 * quién apretó el botón.
 *
 * ## Decisión tomada en T-025
 *
 * UC-2.10 dejaba abierto si existe además una **pausa global del tenant**.
 * **Queda fuera:** ningún criterio de aceptación la pide, y su modo de falla es
 * silencioso y caro — alguien la deja activa un viernes y el bot no atiende a
 * nadie todo el fin de semana, sin que ninguna conversación se vea pausada. La
 * pausa por conversación cubre el caso real ("estoy atendiendo a este cliente
 * a mano"). Si el caso "hoy atendemos todo a mano" aparece, entra como ticket
 * propio con su propio vencimiento visible.
 */
class ConversacionesController extends Controller
{
    /**
     * Cuántas conversaciones por página.
     *
     * Paginado y no un límite fijo: el límite fijo hacía que a partir de la fila
     * 51 el cliente no existiera para el dueño. Veinticinco entra en una
     * pantalla sin scroll infinito y mantiene la consulta barata.
     */
    private const POR_PAGINA = 25;

    /**
     * Cuántos mensajes por pantalla del historial.
     *
     * `messages` es la tabla de mayor crecimiento del sistema: un cliente de
     * meses tiene cientos de mensajes y traerlos todos para dibujar la pantalla
     * es lo que el criterio de T-049 prohíbe.
     */
    private const MENSAJES_POR_PAGINA = 50;

    public function index(Request $request)
    {
        $tenant = $request->user()->tenant;

        // El Global Scope de `BelongsToTenant` ya filtra por el tenant de la
        // sesión: no hace falta —ni conviene— repetir el `where`.
        $pagina = Conversation::query()
            /*
             * T-047 · El turno y su calendario vienen resueltos de una. Sin esto
             * el listado consulta `bookings` una vez por fila y el panel se cae
             * solo cuando el piloto empieza a tener volumen.
             */
            ->with('turno.integration')
            ->orderByDesc('last_interaction_at')
            /*
             * Desempate estable. Sin él, dos conversaciones con la misma última
             * actividad pueden intercambiarse entre una página y la siguiente:
             * el dueño ve dos veces al mismo cliente y nunca al otro.
             */
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        $conversaciones = $pagina->getCollection();

        // Una sola lectura de Redis por conversación: `detalle()` se consulta acá
        // y no dentro del `map`, que además necesita los nombres ya resueltos.
        $pausas = $conversaciones->mapWithKeys(
            fn (Conversation $c) => [$c->id => Pausa::detalle($c)]
        );

        $nombres = $this->nombresDeUsuarios($pausas, (string) $tenant->id);

        $pagina->setCollection($conversaciones->map(fn (Conversation $c) => [
            'id' => $c->id,
            'user_phone' => $c->user_phone,
            'user_name' => $c->user_name,
            'estado' => self::etiquetaDeEstado($c->current_state),
            // RNF-02 · La base guarda UTC; al humano nunca se le muestra UTC.
            'ultima_actividad' => $c->last_interaction_at
                ? HoraLocal::corta($c->last_interaction_at, $tenant)
                : null,
            // AC-14.1 y AC-14.3 · El turno, el monto y el enlace al evento.
            'turno' => $this->datosDelTurno($c->turno, $tenant),
        ] + $this->marcaDePausa($pausas[$c->id], $tenant, $nombres)));

        return view('panel.conversaciones', [
            'tenant' => $tenant,
            'usuario' => $request->user(),
            // T-035 · AC-20.2 · Los pendientes de atender, el más viejo primero.
            'derivaciones' => $this->derivacionesPendientes($tenant),
            'conversaciones' => $pagina,
        ]);
    }

    /**
     * T-049 · AC-14.4 · El detalle: el historial completo de la conversación.
     *
     * Responde al caso de uso original de US-14 —*"recibo un reclamo y lo
     * resuelvo yo en dos minutos"*—, que sin el historial escala a soporte.
     */
    public function mostrar(Request $request, string $conversacion)
    {
        $tenant = $request->user()->tenant;

        // AC-14.2 · Una conversación de otra PyME devuelve 403 y deja rastro.
        $modelo = $this->delTenant($request, $conversacion);
        $modelo->load('turno.integration');

        /*
         * El historial pagina **hacia atrás**: la primera pantalla trae lo
         * último que se dijo, que es lo que resuelve el reclamo, y desde ahí se
         * va al pasado. Por eso se consulta al revés y se invierte recién para
         * dibujar — el orden de lectura sigue siendo cronológico.
         *
         * La relación filtra por `conversation_id` y el Global Scope por
         * `tenant_id`: las dos columnas del índice de T-048, que existe
         * justamente porque esta consulta las necesita a las dos.
         */
        $historial = $modelo->messages()
            ->orderByDesc('created_at')
            // Desempate estable: dos mensajes del mismo segundo no pueden
            // saltar de página al ir hacia atrás.
            ->orderByDesc('id')
            ->paginate(self::MENSAJES_POR_PAGINA)
            ->withQueryString();

        $historial->setCollection(
            $historial->getCollection()->reverse()->values()->map(fn (Message $m) => [
                // AC-14.4 · Quién dijo qué es la mitad del historial.
                'entrante' => $m->direction === Message::ENTRANTE,
                'contenido' => $m->content,
                // RNF-02 · La hora del negocio, nunca la cruda de la base.
                'cuando' => HoraLocal::corta($m->created_at, $tenant),
            ])
        );

        $pausa = Pausa::detalle($modelo);

        return view('panel.conversacion', [
            'tenant' => $tenant,
            'usuario' => $request->user(),
            'historial' => $historial,
            'conversacion' => [
                'id' => $modelo->id,
                'user_phone' => $modelo->user_phone,
                'user_name' => $modelo->user_name,
                'estado' => self::etiquetaDeEstado($modelo->current_state),
                'ultima_actividad' => $modelo->last_interaction_at
                    ? HoraLocal::corta($modelo->last_interaction_at, $tenant)
                    : null,
                'turno' => $this->datosDelTurno($modelo->turno, $tenant),
            ] + $this->marcaDePausa(
                $pausa,
                $tenant,
                $this->nombresDeUsuarios(collect([$pausa]), (string) $tenant->id),
            ),
        ]);
    }

    /** AC-21.1 · El bot deja de responderle a ese cliente. */
    public function pausar(Request $request, string $conversacion): RedirectResponse
    {
        $modelo = $this->delTenant($request, $conversacion);

        // AC-21.4 · Queda registrado quién apretó el botón, no solo que alguien
        // lo apretó: es lo que distingue esta pausa de la automática de T-032.
        Pausa::activar($modelo, Pausa::ORIGEN_MANUAL, (string) $request->user()->id);

        return redirect()->route('panel.conversaciones')->with(
            'exito',
            "El bot dejó de responderle a {$modelo->user_phone}. Se reactiva solo en ".Pausa::MINUTOS.' minutos.',
        );
    }

    /** AC-21.2 · Reactivación anticipada, sin esperar el vencimiento. */
    public function reactivar(Request $request, string $conversacion): RedirectResponse
    {
        $modelo = $this->delTenant($request, $conversacion);

        /*
         * `current_state` no se toca: la pausa vive en Redis justamente para que
         * el bot retome **desde el estado en que había quedado**. Mandarla a
         * `IDLE` haría que un cliente a mitad de una reserva pierda el flujo por
         * haber preguntado algo que le contestó un humano.
         */
        Pausa::levantar($modelo, (string) $request->user()->id);

        return redirect()->route('panel.conversaciones')->with(
            'exito',
            "El bot volvió a atender a {$modelo->user_phone}, desde donde había quedado.",
        );
    }

    /**
     * T-035 · AC-20.3 · Alguien atendió la derivación: el bot vuelve al flujo.
     *
     * Es la única acción que el ticket agrega al panel. Responder desde acá
     * queda **fuera de alcance a propósito**: en el MVP el equipo contesta desde
     * WhatsApp, que es donde ya labura.
     */
    public function resolver(Request $request, string $conversacion): RedirectResponse
    {
        $modelo = $this->delTenant($request, $conversacion);

        if (! Derivacion::estaPendiente($modelo)) {
            // Dos personas abriendo el panel a la vez: la segunda no tiene nada
            // que resolver, y decírselo es mejor que fingir que hizo algo.
            return redirect()->route('panel.conversaciones')->with(
                'exito', 'Esa derivación ya estaba resuelta.',
            );
        }

        Derivacion::resolver($modelo, (string) $request->user()->id);

        return redirect()->route('panel.conversaciones')->with(
            'exito',
            "Listo. El bot vuelve a atender a {$modelo->user_phone}, desde donde había quedado.",
        );
    }

    /**
     * AC-14.1 y AC-14.3 · El turno de la fila: cuándo es, cuánto y dónde abrirlo.
     *
     * @return array<string,string|null>|null
     */
    private function datosDelTurno(?Booking $turno, Tenant $tenant): ?array
    {
        if ($turno === null) {
            return null;
        }

        return [
            // RNF-02 · La base guarda UTC; si acá se imprimiera la hora cruda,
            // un turno de la medianoche se leería el día equivocado.
            'fecha' => HoraLocal::corta($turno->start_time, $tenant),
            /*
             * ⚠️ **El cotizador está congelado** (decisión § 10): hoy
             * `quote_amount` es `null` en todo el sistema. Se devuelve `null` y
             * no un cero: *"no se cotizó"* y *"cotizado en $0"* son dos
             * afirmaciones distintas, y con el cotizador apagado la verdadera es
             * la primera.
             */
            'monto' => $turno->quote_amount === null
                ? null
                : '$ '.number_format((float) $turno->quote_amount, 2, ',', '.'),
            /*
             * AC-14.3 · Sin `integration` no hay calendario al que apuntar, y un
             * enlace roto es peor que ninguno.
             */
            'enlace' => $turno->integration === null
                ? null
                : EnlaceDeCalendario::alEvento(
                    $turno->external_event_id,
                    $turno->integration->account_identifier,
                ),
        ];
    }

    /**
     * AC-14.4 y AC-21.4 · Cómo se lee la pausa: origen, responsable y cuánto le
     * queda.
     *
     * Es la misma marca en el listado y en el detalle a propósito: leer una
     * pausa distinto en cada pantalla es cómo alguien termina escribiéndole
     * encima a un compañero que está atendiendo al cliente a mano.
     *
     * @param  array<string,mixed>|null  $pausa
     * @param  array<int|string,string>  $nombres
     * @return array<string,mixed>
     */
    private function marcaDePausa(?array $pausa, Tenant $tenant, array $nombres): array
    {
        return [
            'pausada' => $pausa !== null,
            // AC-21.4 · Una pausa del panel y una detectada automáticamente
            // se leen distinto: la primera tiene un responsable con nombre.
            'pausa_manual' => $pausa !== null && $pausa['origen'] === Pausa::ORIGEN_MANUAL,
            /*
             * T-035 · La pausa de una derivación se lee al revés que la
             * automática: aquella dice "alguien ya está contestando" y
             * esta dice que **nadie contestó todavía**.
             */
            'pausa_derivacion' => $pausa !== null && $pausa['origen'] === Pausa::ORIGEN_DERIVACION,
            'pausada_por' => $pausa === null
                ? null
                : ($nombres[$pausa['usuario_id'] ?? null] ?? null),
            'pausada_desde' => $pausa === null
                ? null
                : HoraLocal::corta(CarbonImmutable::parse($pausa['desde']), $tenant),
            // AC-21.1 · El tiempo restante, a la vista.
            'minutos_restantes' => $pausa['minutos_restantes'] ?? null,
        ];
    }

    /**
     * AC-20.2 · Los pendientes, ordenados por antigüedad.
     *
     * El Global Scope filtra por el tenant de la sesión: una derivación de otra
     * PyME no puede entrar en esta lista ni siquiera por accidente.
     *
     * @return array<int,array<string,mixed>>
     */
    private function derivacionesPendientes(Tenant $tenant): array
    {
        return Derivacion::pendientes()->get()->map(fn (Conversation $c) => [
            'id' => $c->id,
            'user_phone' => $c->user_phone,
            'user_name' => $c->user_name,
            'motivo' => MotivoDeDerivacion::tryFrom((string) $c->handoff_reason)?->etiqueta()
                ?? (string) $c->handoff_reason,
            // RNF-02 · La hora se muestra en la zona del negocio, nunca en UTC.
            'derivada_el' => HoraLocal::corta($c->handoff_at, $tenant),
            // Lo que decide si alguien tiene que salir corriendo.
            'esperando_hace' => $c->handoff_at->locale('es')->diffForHumans(),
        ])->all();
    }

    /**
     * La conversación, si es de este tenant. Si no, 403 y queda el rastro.
     *
     * Se consulta **sin** el Global Scope a propósito: con el scope, una
     * conversación ajena devolvería 404 y sería indistinguible de un id
     * inexistente. AC-14.2 pide `403` y **registro del intento** — un acceso
     * cruzado que no deja rastro no se puede auditar.
     */
    private function delTenant(Request $request, string $id): Conversation
    {
        $conversacion = Conversation::withoutTenantScope()->find($id);

        if ($conversacion === null) {
            abort(404);
        }

        /*
         * Comparación como **string**: los ids de tenant son UUID. Un `(int)`
         * sobre un UUID devuelve `1` para todos —lo intentamos— y el chequeo de
         * aislamiento pasaría siempre. Es exactamente el modo de falla que
         * RNF-01 no avisa: la app sigue andando y muestra datos de otra PyME.
         */
        if ((string) $conversacion->tenant_id !== (string) $request->user()->tenant_id) {
            Log::warning('Intento de operar sobre una conversación de otro tenant', [
                'tenant_id' => $request->user()->tenant_id,
                'tenant_id_objetivo' => $conversacion->tenant_id,
                'user_id' => $request->user()->id,
                'conversation_id' => $conversacion->id,
                'ruta' => $request->path(),
                'ip' => $request->ip(),
                'codigo' => 'AUTZ_TENANT_CRUZADO',
            ]);

            throw new AccessDeniedHttpException('Esa conversación no es de tu negocio.');
        }

        return $conversacion;
    }

    /**
     * Nombre de quien pausó, para las pausas manuales.
     *
     * `User` no lleva Global Scope —resuelve el tenant, no lo consume—, así que
     * el filtro va explícito. Sin él, un `usuario_id` de otro tenant en una
     * llave de Redis mostraría un nombre ajeno en el panel.
     *
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>|null>  $pausas
     * @return array<int|string,string>
     */
    private function nombresDeUsuarios($pausas, string $tenantId): array
    {
        $ids = $pausas
            ->map(fn (?array $pausa) => $pausa['usuario_id'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->where('tenant_id', $tenantId)
            ->pluck('name', 'id')
            ->all();
    }

    /** El estado en castellano. El enum vive en T-018; acá solo se muestra. */
    private static function etiquetaDeEstado(?string $estado): string
    {
        return match (Estado::tryFrom((string) $estado)) {
            Estado::Idle => 'En reposo',
            Estado::GatheringParams => 'Pidiendo datos',
            Estado::SelectingSlot => 'Eligiendo horario',
            Estado::SlotSelected => 'Confirmando horario',
            Estado::Booked => 'Turno agendado',
            Estado::Confirmed => 'Turno confirmado',
            Estado::Cancelled => 'Turno cancelado',
            Estado::PausedHuman => 'Atendida por una persona',
            Estado::ErrorFallback => 'Falló una integración',
            default => (string) $estado,
        };
    }
}
