<?php

namespace App\Services\Google;

use App\Models\Booking;
use App\Models\Integration;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * T-026 · Creación del evento en Google Calendar.
 *
 * **Es el momento donde el producto genera el valor que cobramos.** Todo lo
 * anterior fue preparación: acá el turno aparece en el calendario que la PyME
 * ya usa, que es la promesa entera de *"sin cambiar tus herramientas"*.
 */
class CalendarioDeGoogle
{
    private const URL_EVENTOS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    /**
     * Marca que identifica los eventos que creamos nosotros.
     *
     * Va en `extendedProperties.private`, que **no se muestra en la interfaz de
     * Google**: el dueño ve un turno normal, no metadatos nuestros.
     *
     * Es el criterio *"el evento queda marcado como creado por el sistema, de
     * forma recuperable"*, y lo van a leer T-027 y T-030 para distinguir lo que
     * agendamos de lo que la PyME cargó a mano — sin eso, la sincronización
     * inversa no puede saber qué liberar.
     */
    public const MARCA_ORIGEN = 'agendallena';

    /**
     * Cuántas páginas de Google se leen como máximo en un solo listado.
     *
     * Es **política, no plomería**, igual que `MINUTOS_ENTRE_CORRIDAS`: es
     * cuántas llamadas estamos dispuestos a gastarle a una PyME antes de dejarla
     * sin barrido inverso. A 250 eventos por página son 5.000 eventos nuestros
     * dentro de la ventana de la conciliación, muy por encima de lo que agenda
     * una PyME de 3 a 20 empleados en dos meses.
     *
     * El tope existe porque **el bucle tiene que cortar**: contra un Google que
     * devuelve `nextPageToken` para siempre —o contra un token que se repite— un
     * `while` sin techo se lleva puesta la corrida entera de todas las PyMEs, y
     * el síntoma desde afuera es que no pasa nada.
     */
    public const MAX_PAGINAS = 20;

    /**
     * ¿El último `eventosPropios()` devolvió una lista **incompleta**?
     *
     * Se expone en vez de devolverse, con el mismo idioma que
     * `Reserva::eventoHuerfano()`: el tipo de retorno ya distingue «no se pudo
     * leer» (`null`) de la lista, y meterle un tercer caso obligaría a los
     * llamadores que no les importa —el que solo reporta huérfanos— a
     * desempaquetar una tupla.
     */
    private bool $ultimoListadoTruncado = false;

    public function __construct(private readonly GoogleConnection $conexion) {}

    /**
     * `true` si el último listado vino con `nextPageToken`.
     *
     * Quien **cancela** algo a partir de esa lista tiene que preguntarlo: lo que
     * quedó en la página no leída es indistinguible de lo que no existe, y esa
     * confusión cuesta turnos reales. Al que solo reporta le da igual.
     */
    public function ultimoListadoTruncado(): bool
    {
        return $this->ultimoListadoTruncado;
    }

    /**
     * Crea el evento y devuelve su `id` de Google.
     *
     * @param  string|null  $idempotencia  T-030 · AC-22.3 · El `id` del evento,
     *   fijado por nosotros. Es el único mecanismo de idempotencia que ofrece
     *   `events.insert`: con él, un reintento recibe `409 duplicate` y **no**
     *   crea un segundo turno sobre el mismo horario. Ver `ClaveDeIdempotencia`.
     *
     * @throws GoogleIntegracionVencida  si hay que reconectar la cuenta.
     */
    public function crearEvento(
        Integration $integration,
        Tenant $tenant,
        CarbonImmutable $inicio,
        CarbonImmutable $fin,
        string $nombreCliente,
        string $telefono,
        string $servicio,
        ?float $monto = null,
        ?int $bookingId = null,
        ?string $idempotencia = null,
    ): ?string {
        $cuerpo = [
            'summary' => "{$servicio} · {$nombreCliente}",
            'description' => $this->descripcion($nombreCliente, $telefono, $servicio, $monto),

            /*
             * Los instantes viajan en RFC3339 con offset, y además se declara
             * `timeZone`. Google podría deducirlo del offset, pero mandarlo
             * explícito hace que el evento se muestre en la zona del negocio
             * aunque el dueño abra el calendario desde otro huso.
             */
            'start' => [
                'dateTime' => $inicio->setTimezone($tenant->timezone)->toRfc3339String(),
                'timeZone' => $tenant->timezone,
            ],
            'end' => [
                'dateTime' => $fin->setTimezone($tenant->timezone)->toRfc3339String(),
                'timeZone' => $tenant->timezone,
            ],

            'extendedProperties' => [
                'private' => array_filter([
                    'origen' => self::MARCA_ORIGEN,
                    'tenant_id' => $tenant->id,
                    'booking_id' => $bookingId !== null ? (string) $bookingId : null,
                ]),
            ],
        ];

        // El `id` va primero solo por legibilidad del cuerpo; a Google le da igual.
        if ($idempotencia !== null && $idempotencia !== '') {
            $cuerpo = ['id' => $idempotencia] + $cuerpo;
        }

        $respuesta = $this->conexion->ejecutar($integration, fn (string $token) => Http::withToken($token)
            ->timeout(30)
            ->post(self::URL_EVENTOS, $cuerpo));

        /*
         * T-030 · AC-22.3 · `409 duplicate` **no es un fallo**: es Google
         * diciendo que el evento con esa clave ya existe, o sea que un intento
         * anterior lo creó. Tratarlo como error devolvería `null`, y desde ahí
         * `Fallback::liberarEvento()` borraría del calendario un turno real y le
         * pediría disculpas al cliente por un turno que quedó bien.
         */
        if ($respuesta->status() === 409 && $idempotencia !== null) {
            Log::info('El evento ya existía en Google: el reintento converge al mismo turno', [
                'tenant_id' => $tenant->id,
                'integracion' => 'google_calendar',
                'codigo' => 'CALENDAR_EVENTO_IDEMPOTENTE',
                'external_event_id' => $idempotencia,
            ]);

            return $idempotencia;
        }

        if (! $respuesta->successful()) {
            Log::error('Google rechazó la creación del evento', [
                'tenant_id' => $tenant->id,
                'integracion' => 'google_calendar',
                'codigo' => 'CALENDAR_EVENTO_RECHAZADO',
                'http' => $respuesta->status(),
                'error' => $respuesta->json('error.message'),
            ]);

            return null;
        }

        return $respuesta->json('id');
    }

    /**
     * T-030 · Los eventos **que creamos nosotros** en el calendario del tenant.
     *
     * Lo consume la conciliación. El filtro va del lado de Google con
     * `privateExtendedProperty`, y no en PHP después de traer todo: el
     * calendario del dueño tiene su vida entera adentro —el dentista, el asado,
     * los turnos que cargó por teléfono— y ninguno de esos es asunto nuestro.
     *
     * Se filtra **también por `tenant_id`** y no solo por `origen`: si dos PyMEs
     * conectaran la misma cuenta de Google, los eventos de una no pueden entrar
     * en la conciliación de la otra (RNF-01).
     *
     * Sigue el `nextPageToken` hasta traer el calendario completo, con un tope
     * de `MAX_PAGINAS`. Un `nextPageToken` no significa "me quedé corto" sino
     * "hay más, andá a buscarlo": el listado solo queda marcado como truncado
     * cuando el tope se agota con el token todavía presente, o cuando una página
     * intermedia falla.
     *
     * @return array<int,array<string,mixed>>|null  `null` si **no se pudo
     *   consultar**. No es lo mismo que "no hay eventos": un calendario que no
     *   se pudo leer no autoriza a concluir nada. Y una lista con
     *   `ultimoListadoTruncado()` en `true` es un **prefijo** del calendario.
     *
     * @throws GoogleIntegracionVencida  si hay que reconectar la cuenta.
     */
    public function eventosPropios(
        Integration $integration,
        Tenant $tenant,
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
    ): ?array {
        $this->ultimoListadoTruncado = false;

        $eventos = [];
        $pageToken = null;

        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $query = $this->queryDelListado($tenant, $desde, $hasta, $pageToken);

            $respuesta = $this->conexion->ejecutar($integration, fn (string $token) => Http::withToken($token)
                ->timeout(30)
                ->get(self::URL_EVENTOS.'?'.$query));

            if (! $respuesta->successful()) {
                Log::error('Google rechazó el listado de eventos para la conciliación', [
                    'tenant_id' => $tenant->id,
                    'integracion' => 'google_calendar',
                    'codigo' => 'CALENDAR_LISTADO_RECHAZADO',
                    'http' => $respuesta->status(),
                    'pagina' => $pagina,
                ]);

                /*
                 * Falló la **primera** página: no se leyó nada, y eso es
                 * exactamente lo que `null` significa. Devolver una lista vacía
                 * diría "no hay eventos", que es la conclusión que hace cancelar
                 * turnos vivos.
                 */
                if ($pagina === 0) {
                    return null;
                }

                /*
                 * Falló una página **intermedia**: lo que queda en la mano es un
                 * prefijo del calendario, la misma información que deja agotar el
                 * tope, y por eso se trata igual. El prefijo sirve para reportar
                 * huérfanos —un evento leído que ninguna fila reclama lo es
                 * aunque falte leer el resto— y el flag frena lo que sí es
                 * destructivo. Media lista nunca se devuelve como completa.
                 */
                $this->marcarTruncado($tenant, 'una página del listado falló', $pagina);

                return $eventos;
            }

            foreach ($respuesta->json('items') ?? [] as $evento) {
                $eventos[] = $evento;
            }

            $pageToken = $respuesta->json('nextPageToken');

            // Sin `nextPageToken` el calendario terminó: la lista está completa y
            // el barrido inverso tiene derecho a cancelar sobre ella.
            if ($pageToken === null || $pageToken === '') {
                return $eventos;
            }
        }

        /*
         * Se agotó el tope y Google sigue diciendo que hay más. Es el caso
         * patológico, y acá vuelve a valer la red de siempre: lo que no se leyó
         * es indistinguible de lo que no existe.
         */
        $this->marcarTruncado($tenant, 'se agotó el tope de páginas', self::MAX_PAGINAS);

        return $eventos;
    }

    /**
     * La query del listado, con los filtros y —desde la segunda página— el
     * `pageToken`.
     *
     * Se arma a mano porque `privateExtendedProperty` **se repite** y Google la
     * lee así. El armador de Laravel convertiría un array en
     * `privateExtendedProperty[0]=...`, que Google ignora: la consulta volvería
     * sin filtrar y la conciliación miraría la agenda entera.
     *
     * ⚠️ Los filtros van en **todas** las páginas, no solo en la primera: Google
     * no recuerda la consulta detrás de un `pageToken`. Una página pedida sin
     * `privateExtendedProperty` devuelve la agenda personal del dueño —el
     * dentista, el asado, los turnos que cargó por teléfono— y cada uno de esos
     * entraría a la conciliación como un evento nuestro sin fila. El aislamiento
     * por `tenant_id` (RNF-01) viaja en ese mismo filtro.
     */
    private function queryDelListado(
        Tenant $tenant,
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        ?string $pageToken,
    ): string {
        $parametros = [
            'timeMin' => $desde->utc()->toRfc3339String(),
            'timeMax' => $hasta->utc()->toRfc3339String(),
            // Sin esto, un evento recurrente vuelve como una sola fila y sus
            // instancias —que son las que ocupan horario— no se ven.
            'singleEvents' => 'true',
            'maxResults' => 250,
        ];

        // La primera página no tiene de dónde sacar un token, así que el
        // parámetro directamente no viaja: Google rechaza un `pageToken` vacío.
        if ($pageToken !== null && $pageToken !== '') {
            $parametros['pageToken'] = $pageToken;
        }

        $query = http_build_query($parametros);

        foreach (['origen='.self::MARCA_ORIGEN, 'tenant_id='.$tenant->id] as $filtro) {
            $query .= '&privateExtendedProperty='.urlencode($filtro);
        }

        return $query;
    }

    /**
     * Deja constancia de que el listado quedó a medias y prende el flag.
     *
     * RNF-03 · Hacen falta los dos: el log no lo puede leer el código, y el flag
     * no lo puede leer una persona. El barrido inverso de T-030 **cancela
     * turnos** a partir de esta lista y necesita enterarse antes de tocar nada.
     */
    private function marcarTruncado(Tenant $tenant, string $motivo, int $paginasLeidas): void
    {
        $this->ultimoListadoTruncado = true;

        Log::warning('El calendario tiene más eventos de los que se revisaron', [
            'tenant_id' => $tenant->id,
            'integracion' => 'google_calendar',
            'codigo' => 'CONCILIACION_LISTADO_TRUNCADO',
            'motivo' => $motivo,
            'paginas_leidas' => $paginasLeidas,
        ]);
    }

    /** Borra el evento. Lo usa la cancelación y la compensación de T-030. */
    public function borrarEvento(Integration $integration, string $eventId): bool
    {
        $respuesta = $this->conexion->ejecutar($integration, fn (string $token) => Http::withToken($token)
            ->timeout(30)
            ->delete(self::URL_EVENTOS.'/'.urlencode($eventId)));

        // Google devuelve 410 si el evento ya no existe: para nosotros es éxito,
        // porque el estado final deseado —que no esté— ya se cumple.
        return $respuesta->successful() || $respuesta->status() === 410;
    }

    /**
     * T-037 · ¿El evento sigue en el calendario del negocio?
     *
     * **Es el recorte Pareto de T-037** y cubre el hueco que deja diferir T-027
     * (sincronización inversa): si el dueño borró el turno desde su propio Google
     * Calendar, para él ese turno no existe. Mandarle el recordatorio al cliente
     * lo hace ir a un turno que nadie va a atender.
     *
     * Ante una respuesta que no se puede interpretar —un 500, un timeout— se
     * devuelve `false`: **no verificar no es lo mismo que verificar que está**, y
     * la corrida siguiente de la tarea vuelve a intentarlo mientras la ventana
     * siga abierta. Quien llama registra por qué no se mandó (RNF-03).
     *
     * @throws GoogleIntegracionVencida  si hay que reconectar la cuenta.
     */
    public function eventoSigueExistiendo(Integration $integration, string $eventId): bool
    {
        $respuesta = $this->conexion->ejecutar($integration, fn (string $token) => Http::withToken($token)
            ->timeout(30)
            ->get(self::URL_EVENTOS.'/'.urlencode($eventId)));

        if (! $respuesta->successful()) {
            Log::info('El evento del turno ya no está en Google Calendar', [
                'tenant_id' => $integration->tenant_id,
                'integracion' => 'google_calendar',
                'codigo' => 'CALENDAR_EVENTO_AUSENTE',
                'http' => $respuesta->status(),
            ]);

            return false;
        }

        /*
         * Google no borra los eventos cancelados: los deja con `status:
         * cancelled`. Un `200` no alcanza para decir que el turno sigue en pie.
         */
        return $respuesta->json('status') !== 'cancelled';
    }

    /** ¿Este evento lo creamos nosotros? Lo consume T-027. */
    public static function loCreamosNosotros(array $evento): bool
    {
        return ($evento['extendedProperties']['private']['origen'] ?? null) === self::MARCA_ORIGEN;
    }

    private function descripcion(string $nombre, string $telefono, string $servicio, ?float $monto): string
    {
        $lineas = [
            "Cliente: {$nombre}",
            "Teléfono: {$telefono}",
            "Servicio: {$servicio}",
        ];

        /*
         * ⚠️ El monto queda vacío mientras el cotizador esté diferido (T-016,
         * T-040, T-041). El criterio de T-026 lo nombra —*"con nombre, teléfono
         * y monto cotizado"*— pero no hay de dónde sacarlo: escribir un cero
         * sería peor que no escribirlo.
         */
        if ($monto !== null) {
            $lineas[] = 'Presupuesto: $'.number_format($monto, 2, ',', '.');
        }

        $lineas[] = '';
        $lineas[] = 'Agendado por AgendaLlena.';

        return implode("\n", $lineas);
    }
}
