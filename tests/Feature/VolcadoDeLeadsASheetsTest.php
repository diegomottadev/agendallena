<?php

namespace Tests\Feature;

use App\Jobs\ProcessMessageJob;
use App\Models\Booking;
use App\Models\Integration;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * T-045 · US-13 · Los leads caen en la planilla donde el equipo ya trabaja.
 *
 * Es la prueba literal de *"sin obligarte a cambiar de herramientas"*, la mitad
 * de la propuesta de valor y el diferencial frente a los CRM que la PyME ya
 * rechazó. Y cierra **AC-22.4**, uno de los tres criterios del núcleo que
 * quedaron declarados sin cubrir.
 *
 * ## Lo que este archivo decide, porque el criterio no lo decía
 *
 * ⚠️ **El orden de las columnas** es el que enumera AC-13.1: fecha, teléfono,
 * nombre, monto cotizado, estado. Después van las dos que agrega AC-13.2 al
 * reservar —fecha del turno y enlace al evento—. Nadie lo escribió en un RF;
 * sale de leer el criterio en el orden en que está escrito.
 *
 * ⚠️ **El valor del estado no se afirma.** Que diga «lead», «consulta» o
 * «pendiente» es una decisión de producto que nadie tomó. Se afirma que la celda
 * **no viene vacía**, que es lo único que la vuelve útil.
 *
 * ⚠️ **La columna de monto va vacía**, y eso es correcto: el cotizador está
 * congelado (decisión § 10) y `quote_amount` es siempre `null` hoy. La columna
 * tiene que existir igual — cuando el cotizador exista, la planilla de las PyMEs
 * que ya están andando no puede cambiar de forma.
 *
 * ⚠️ **Cómo se identifica la fila para actualizarla** queda abierto: el test
 * afirma que la segunda escritura **cae sobre la fila que devolvió el `append`**
 * y que no se agrega una segunda fila, sin decidir dónde se guarda esa
 * referencia. T-045 la marca como hueco («no hay columna donde persistirla») y
 * ese hueco es de quien implemente.
 *
 * ## Trampas que este archivo evita a propósito
 *
 * - **Un solo `Http::fake()` por test.** Acumula stubs: un segundo `fake()` no
 *   reemplaza al primero y el doble del error nunca se consume.
 * - **Los negativos llevan canario.** «No se duplicó la fila» y «la planilla
 *   vieja no recibió nada» pasan en vacío si el volcado no escribió nunca. En
 *   cada test hay algo que **sí** tiene que haber ocurrido.
 * - **`tenant_id` es UUID**: se compara como string. Un `(int)` sobre un UUID
 *   devuelve `1` para todos y el test de aislamiento pasaría siempre.
 */
class VolcadoDeLeadsASheetsTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID = '1053554814514902';

    /** El número del vecino. No es substring del otro: la aserción distingue. */
    private const PHONE_ID_VECINO = '7742019983365574';

    private const TELEFONO = '5493764278402';

    private const TELEFONO_OTRO = '5491133445566';

    private const TZ = 'America/Argentina/Buenos_Aires';

    /** Lunes a la mañana: el flujo encuentra horarios en la agenda por defecto. */
    private const AHORA = '2026-08-24 08:00';

    private const PLANILLA = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms';

    private const PLANILLA_NUEVA = '1ZfTkOaXwHYtNbmXpQrSvWxYz0123456789AbCdEfGh';

    private const PLANILLA_DEL_VECINO = '1QqRrSsTtUuVvWwXxYyZz0987654321aAbBcCdDeE';

    private Tenant $tenant;

    private User $duenio;

    private Integration $google;

    /** @var array<string,bool> Las planillas que existen del lado de Google. */
    private array $planillasEnGoogle = [];

    /** @var array<string,int> Última fila ocupada por planilla. La 1 es el encabezado. */
    private array $ultimaFila = [];

    /** @var array<string,string> Los eventos creados: `id => htmlLink`. */
    private array $eventos = [];

    private int $idsInventados = 0;

    private int $salientes = 0;

    /** Sheets contesta 500 a todo. Es el escenario de AC-13.3 / AC-22.4. */
    private bool $sheetsCaido = false;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, self::TZ));
        config()->set('services.meta.phone_number_id', self::PHONE_ID);

        [$this->tenant, $this->duenio, $this->google] = $this->pyme(
            'Peluquería Sur', 'piloto', self::PHONE_ID
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ montaje

    /** @return array{0:Tenant,1:User,2:Integration} */
    private function pyme(string $nombre, string $slug, string $phoneNumberId): array
    {
        $tenant = Tenant::create([
            'name' => $nombre, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId,
            'access_token' => 'meta-'.$slug,
            'settings' => ['verify_token' => 'tok-'.$slug],
            'status' => 'connected',
        ]);

        $google = Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio@'.$slug.'.com',
            'access_token' => 'ya29.'.$slug,
            'refresh_token' => '1//r-'.$slug,
            'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        $duenio = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Dueña de '.$nombre,
            'email' => 'duenia@'.$slug.'.test',
            'password' => 'secreto123',
            'role' => Role::Owner,
        ]);

        return [$tenant, $duenio, $google];
    }

    /**
     * Vincula la planilla por donde lo hace la PyME: la pantalla de T-044.
     *
     * Escribirla a mano en la base ataría el test a un esquema que todavía no
     * existe, y además dejaría sin ejercer el único camino que la crea.
     */
    private function vincular(User $duenio, string $spreadsheetId, string $hoja = 'Leads'): void
    {
        $this->planillasEnGoogle[$spreadsheetId] = true;

        $this->actingAs($duenio)
            ->put('/panel/configuracion/planilla', [
                'url' => "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit#gid=0",
                'hoja' => $hoja,
            ])
            ->assertSessionHasNoErrors()
            // Si la pantalla de T-044 todavía no existe, que se vea acá y no
            // tres aserciones más abajo disfrazado de «el lead no llegó».
            ->assertRedirect('/panel/configuracion/planilla');

        $this->app['auth']->forgetGuards();
    }

    // ------------------------------------------------------------- el doble

    /**
     * **Un solo `Http::fake()` por test**: Sheets, Calendar y Meta en un mapa.
     *
     * ⚠️ `Http::fake()` acumula stubs. Un segundo `fake()` para «ahora Sheets
     * falla» no reemplaza al primero: el doble del error nunca se consume y el
     * test pasa contra la respuesta feliz. Ya pasó tres veces en T-026. Por eso
     * el caído es un **estado del doble**, no un segundo `fake()`.
     */
    private function fakes(): void
    {
        Http::fake([
            'sheets.googleapis.com/*' => function (Request $request) {
                if ($this->sheetsCaido) {
                    return Http::response([
                        'error' => ['code' => 500, 'message' => 'Internal error encountered.',
                            'status' => 'INTERNAL'],
                    ], 500);
                }

                $ruta = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && rtrim($ruta, '/') === '/v4/spreadsheets') {
                    $id = 'planilla_creada';
                    $this->planillasEnGoogle[$id] = true;

                    return Http::response([
                        'spreadsheetId' => $id,
                        'spreadsheetUrl' => "https://docs.google.com/spreadsheets/d/{$id}/edit",
                        'sheets' => [['properties' => ['sheetId' => 0, 'title' => 'Leads']]],
                    ]);
                }

                $id = preg_match('#/v4/spreadsheets/([^/:?]+)#', $ruta, $m) === 1 ? $m[1] : '';

                if (! isset($this->planillasEnGoogle[$id])) {
                    return Http::response([
                        'error' => ['code' => 404, 'message' => 'Requested entity was not found.',
                            'status' => 'NOT_FOUND'],
                    ], 404);
                }

                if ($request->method() === 'GET') {
                    return Http::response([
                        'spreadsheetId' => $id,
                        'properties' => ['title' => 'Seguimiento comercial'],
                        'sheets' => [
                            ['properties' => ['sheetId' => 0, 'title' => 'Hoja 1']],
                            ['properties' => ['sheetId' => 71, 'title' => 'Leads']],
                        ],
                    ]);
                }

                // Escritura. Un `append` ocupa una fila nueva; cualquier otra
                // cosa escribe donde le digan y no mueve el contador.
                $esAppend = str_contains($request->url(), ':append');
                $fila = $this->ultimaFila[$id] ?? 1;

                if ($esAppend) {
                    $fila = ++$this->ultimaFila[$id];
                }

                return Http::response([
                    'spreadsheetId' => $id,
                    'updates' => [
                        'spreadsheetId' => $id,
                        'updatedRange' => "Leads!A{$fila}:G{$fila}",
                        'updatedRows' => 1,
                    ],
                    'updatedRange' => "Leads!A{$fila}:G{$fila}",
                    'updatedRows' => 1,
                ]);
            },

            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),

            'www.googleapis.com/calendar/v3/calendars/primary/events*' => function (Request $request) {
                if ($request->method() === 'DELETE') {
                    return Http::response([], 204);
                }

                $id = $request->data()['id'] ?? 'evt_google_'.(++$this->idsInventados);
                $link = 'https://www.google.com/calendar/event?eid='.$id;
                $this->eventos[$id] = $link;

                return Http::response(['id' => $id, 'status' => 'confirmed', 'htmlLink' => $link]);
            },

            'graph.facebook.com/*' => fn () => Http::response([
                'messages' => [['id' => 'wamid.OUT_'.(++$this->salientes)]],
            ]),
        ]);
    }

    /** El contador de filas arranca en el encabezado, como una planilla real. */
    private function conPlanilla(string $id): void
    {
        $this->planillasEnGoogle[$id] = true;
        $this->ultimaFila[$id] = 1;
    }

    // -------------------------------------------------- lectura de lo enviado

    /**
     * Las escrituras que salieron hacia una planilla.
     *
     * @return array<int,array{metodo:string,url:string,cuerpo:array<mixed>,fila:?int,append:bool}>
     */
    private function escriturasEn(string $spreadsheetId, int $desde = 0): array
    {
        $out = [];
        $n = 0;

        foreach (Http::recorded() as [$req, $res]) {
            if (! str_contains($req->url(), 'sheets.googleapis.com')) {
                continue;
            }

            if (! str_contains($req->url(), $spreadsheetId)) {
                continue;
            }

            if ($req->method() === 'GET') {
                continue;
            }

            $n++;

            if ($n <= $desde) {
                continue;
            }

            $rango = (string) ($res->json('updates.updatedRange') ?? $res->json('updatedRange') ?? '');
            $fila = preg_match('/[A-Z]+(\d+)/', $rango, $m) === 1 ? (int) $m[1] : null;

            $out[] = [
                'metodo' => $req->method(),
                'url' => urldecode($req->url()),
                'cuerpo' => (array) $req->data(),
                'fila' => $fila,
                'append' => str_contains($req->url(), ':append'),
            ];
        }

        return $out;
    }

    /** Cuántas peticiones salieron hacia Sheets, sea a la planilla que sea. */
    private function pedidosASheets(): int
    {
        $n = 0;

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'sheets.googleapis.com')) {
                $n++;
            }
        }

        return $n;
    }

    /** Solo los dígitos: el formato del teléfono no puede decidir si el test pasa. */
    private function soloDigitos(string $texto): string
    {
        return (string) preg_replace('/\D+/', '', $texto);
    }

    /**
     * Las escrituras que llevan los datos de ese teléfono.
     *
     * @return array<int,array<string,mixed>>
     */
    private function escriturasCon(string $spreadsheetId, string $telefono): array
    {
        $out = [];

        foreach ($this->escriturasEn($spreadsheetId) as $escritura) {
            $texto = json_encode($escritura['cuerpo'], JSON_UNESCAPED_UNICODE);

            if (str_contains($this->soloDigitos((string) $texto), $this->soloDigitos($telefono))) {
                $out[] = $escritura;
            }
        }

        return $out;
    }

    /**
     * La fila que se mandó a escribir, celda por celda.
     *
     * @return array<int,string>
     */
    private function celdasDe(array $escritura): array
    {
        $valores = $escritura['cuerpo']['values'][0] ?? [];

        return array_map(fn ($c) => is_scalar($c) ? (string) $c : '', (array) $valores);
    }

    /** ¿Esa escritura apunta a la fila `$fila` de la planilla? */
    private function apuntaALaFila(array $escritura, int $fila): bool
    {
        $texto = $escritura['url'].' '.json_encode($escritura['cuerpo']);

        return preg_match('/[A-Z]'.$fila.'(?!\d)/', $texto) === 1
            || str_contains($texto, '"startRowIndex":'.($fila - 1))
            || str_contains($texto, '"rowIndex":'.($fila - 1));
    }

    // ------------------------------------------------------ webhooks entrantes

    /** @return array<string,mixed> */
    private function texto(string $cuerpo, string $telefono = self::TELEFONO, string $phoneId = self::PHONE_ID): array
    {
        return $this->payload([
            'from' => $telefono, 'id' => 'wamid.'.uniqid(),
            'type' => 'text', 'text' => ['body' => $cuerpo],
        ], $phoneId);
    }

    /** @return array<string,mixed> */
    private function toca(string $id, string $telefono = self::TELEFONO, string $phoneId = self::PHONE_ID): array
    {
        return $this->payload([
            'from' => $telefono, 'id' => 'wamid.'.uniqid(),
            'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => 'x']],
        ], $phoneId);
    }

    /** @return array<string,mixed> */
    private function payload(array $mensaje, string $phoneId = self::PHONE_ID): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => $phoneId], 'messages' => [$mensaje]],
        ]]]]];
    }

    /**
     * Lo que Meta **aceptó**: lo que el cliente realmente vio en el chat.
     *
     * @return array<int,array<string,mixed>>
     */
    private function entregados(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com') && $res->successful()) {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    private function alguienDijo(string $fragmento): bool
    {
        foreach ($this->entregados() as $m) {
            if (is_string($m['text']['body'] ?? null) && str_contains($m['text']['body'], $fragmento)) {
                return true;
            }
        }

        return false;
    }

    // --------------------------------------------------------- el flujo real

    /**
     * Hasta dar el nombre: el momento en que la conversación sale de
     * `GATHERING_PARAMS` y el lead queda capturado (decisión de T-007).
     */
    private function llegarHastaElNombre(string $nombre, string $telefono = self::TELEFONO, string $phoneId = self::PHONE_ID): void
    {
        $antes = count($this->entregados());

        (new ProcessMessageJob($this->texto('Hola', $telefono, $phoneId)))->handle();

        $bienvenida = $this->entregados()[$antes] ?? null;
        $reservar = $bienvenida['interactive']['action']['buttons'][0]['reply']['id'] ?? null;

        $this->assertNotNull($reservar, 'No llegó la bienvenida con el botón de reservar.');

        (new ProcessMessageJob($this->toca($reservar, $telefono, $phoneId)))->handle();
        (new ProcessMessageJob($this->texto($nombre, $telefono, $phoneId)))->handle();
    }

    /** Elige el primer horario de la lista y agenda. */
    private function reservarElPrimerHorario(string $telefono = self::TELEFONO, string $phoneId = self::PHONE_ID): void
    {
        foreach (array_reverse($this->entregados()) as $m) {
            if (($m['interactive']['type'] ?? null) === 'list') {
                $fila = $m['interactive']['action']['sections'][0]['rows'][0];

                (new ProcessMessageJob($this->toca($fila['id'], $telefono, $phoneId)))->handle();

                return;
            }
        }

        $this->fail('Nunca se ofreció la lista de horarios: el flujo no llegó a la reserva.');
    }

    private function turnoDe(Tenant $tenant): ?Booking
    {
        return TenantContext::runAs($tenant->id, fn () => Booking::query()->first());
    }

    // ---------------------------------------------------- AC-13.1 · alta del lead

    /**
     * **AC-13.1.** El lead queda escrito **al dar el nombre**, no al reservar.
     *
     * Es la razón entera por la que la captura del nombre se movió a
     * `GATHERING_PARAMS` (decisión cerrada en T-007): el lead que se va sin
     * reservar es justamente el que US-13 existe para capturar. Si la fila
     * apareciera recién al confirmar el turno, la planilla tendría solo a los
     * que ya son clientes — que son los que la PyME menos necesita perseguir.
     */
    public function test_al_salir_de_gathering_params_el_lead_queda_en_la_planilla(): void
    {
        $this->fakes();
        $this->conPlanilla(self::PLANILLA);
        $this->vincular($this->duenio, self::PLANILLA);

        $this->llegarHastaElNombre('María Gómez');

        $escrituras = $this->escriturasCon(self::PLANILLA, self::TELEFONO);

        $this->assertCount(1, $escrituras,
            'El cliente dio su nombre y su lead no llegó a la planilla —o llegó más de una '
            .'vez—. Escrituras con ese teléfono: '.count($escrituras));

        $celdas = $this->celdasDe($escrituras[0]);

        $this->assertGreaterThanOrEqual(5, count($celdas),
            'La fila del lead no tiene las cinco columnas de AC-13.1: '.json_encode($celdas));

        // Fecha del lead, en la zona del negocio.
        $this->assertTrue(
            str_contains($celdas[0], '2026-08-24') || str_contains($celdas[0], '24/08/2026'),
            'La primera columna no es la fecha del lead: «'.$celdas[0].'»'
        );

        $this->assertSame($this->soloDigitos(self::TELEFONO), $this->soloDigitos($celdas[1]),
            'La segunda columna no es el teléfono, que es el único dato con el que la PyME '
            .'puede volver a contactarlo: «'.$celdas[1].'»');

        $this->assertSame('María Gómez', $celdas[2],
            'El lead no se escribió con el nombre completo que dio el cliente: «'.$celdas[2].'»');

        // ⚠️ El cotizador está congelado (decisión § 10): la columna existe y va
        // vacía. Un valor acá sería un cotizador inventado.
        $this->assertSame('', trim($celdas[3]),
            'La columna de monto trae algo y no hay cotizador: «'.$celdas[3].'»');

        $this->assertNotSame('', trim($celdas[4]),
            'La columna de estado vino vacía: la PyME no puede distinguir un lead que abandonó '
            .'de uno que ya reservó.');
    }

    /**
     * **AC-13.1.** El lead se escribe en **su** hoja, no en la primera que haya.
     *
     * La dueña eligió una hoja en T-044 justamente porque su planilla tiene
     * otras: escribir en «Hoja 1» le pisa la que ya estaba usando.
     */
    public function test_el_lead_se_escribe_en_la_hoja_que_eligio_la_duenia(): void
    {
        $this->fakes();
        $this->conPlanilla(self::PLANILLA);
        $this->vincular($this->duenio, self::PLANILLA, 'Leads');

        $this->llegarHastaElNombre('María Gómez');

        $escrituras = $this->escriturasCon(self::PLANILLA, self::TELEFONO);

        $this->assertCount(1, $escrituras, 'El lead no llegó a la planilla.');

        $this->assertStringContainsString('Leads', $escrituras[0]['url'],
            'La escritura no nombra la hoja elegida: '.$escrituras[0]['url']);
    }

    // ------------------------------------- AC-13.2 · actualizar sin duplicar

    /**
     * **AC-13.2.** El lead que reserva **actualiza su fila**; no aparece dos veces.
     *
     * Dos filas por la misma persona rompen el seguimiento comercial que la
     * planilla existe para sostener: el equipo llama dos veces al mismo, y la
     * cuenta de leads del mes queda inflada.
     */
    public function test_cuando_el_lead_reserva_se_actualiza_su_fila_y_no_se_agrega_otra(): void
    {
        $this->fakes();
        $this->conPlanilla(self::PLANILLA);
        $this->vincular($this->duenio, self::PLANILLA);

        $this->llegarHastaElNombre('María Gómez');

        $alta = $this->escriturasCon(self::PLANILLA, self::TELEFONO);
        $this->assertCount(1, $alta, 'El escenario no quedó montado: el alta del lead no ocurrió.');

        $filaDelLead = $alta[0]['fila'];
        $this->assertNotNull($filaDelLead, 'Google devolvió el rango escrito y nadie lo leyó.');

        $this->reservarElPrimerHorario();

        // Canario: sin turno no hay nada que actualizar y el test no probaría nada.
        $turno = $this->turnoDe($this->tenant);
        $this->assertNotNull($turno, 'No se agendó el turno: el escenario no llegó a AC-13.2.');

        $escrituras = $this->escriturasCon(self::PLANILLA, self::TELEFONO);
        $altas = array_values(array_filter($escrituras, fn ($e) => $e['append'] === true));

        $this->assertCount(1, $altas,
            'La reserva agregó una fila nueva en vez de actualizar la del lead: la misma '
            .'persona queda dos veces en la planilla.');

        $actualizaciones = array_values(array_filter($escrituras, fn ($e) => $e['append'] === false));

        $this->assertNotSame([], $actualizaciones,
            'El lead reservó y su fila quedó igual: la planilla dice que sigue siendo una '
            .'consulta sin turno.');

        $laFila = array_values(array_filter(
            $actualizaciones,
            fn ($e) => $this->apuntaALaFila($e, $filaDelLead)
        ));

        $this->assertNotSame([], $laFila,
            "La actualización no apunta a la fila {$filaDelLead}, que es donde había quedado "
            .'el lead. Escribir en otra fila pisa datos de otro lead. Escrituras: '
            .json_encode(array_map(fn ($e) => $e['url'], $actualizaciones)));

        $contenido = json_encode($laFila[0]['cuerpo'], JSON_UNESCAPED_UNICODE);
        $inicio = $turno->start_time->setTimezone(self::TZ);

        $this->assertTrue(
            str_contains((string) $contenido, $inicio->format('Y-m-d'))
                || str_contains((string) $contenido, $inicio->format('d/m/Y')),
            'La fila actualizada no lleva la fecha del turno: '.$contenido
        );

        $this->assertTrue(
            str_contains((string) $contenido, (string) $turno->external_event_id),
            'La fila actualizada no lleva el enlace al evento, que es lo que le permite a la '
            .'PyME saltar del renglón de la planilla al turno en su calendario: '.$contenido
        );
    }

    // ---------------------------- AC-13.3 y AC-22.4 · Sheets no manda sobre el turno

    /**
     * **AC-22.4 · el criterio que este trabajo viene a cerrar.**
     *
     * Un fallo de Google Sheets **no puede impedir que el turno quede creado y
     * confirmado**. El turno es lo que se cobra; la planilla es conveniencia.
     * Que el cliente se quede sin horario porque una API de terceros devolvió
     * un 500 es el peor intercambio posible.
     */
    public function test_un_fallo_de_sheets_no_impide_el_turno_ni_corta_la_conversacion(): void
    {
        $this->fakes();
        $this->conPlanilla(self::PLANILLA);
        $this->vincular($this->duenio, self::PLANILLA);

        // Recién ahora se cae: la vinculación ya está hecha. Es un estado del
        // mismo doble y **no** un segundo `Http::fake()`.
        $this->sheetsCaido = true;
        $pedidosAntes = $this->pedidosASheets();

        $this->llegarHastaElNombre('María Gómez');
        $this->reservarElPrimerHorario();

        // Canario: si nadie intentó escribir, el test pasa sin haber ejercido nada.
        $this->assertGreaterThan($pedidosAntes, $this->pedidosASheets(),
            'Con Sheets caído no salió ni un intento de escritura: el volcado no se ejerció y '
            .'este test no prueba que el fallo se tolera.');

        $turno = $this->turnoDe($this->tenant);

        $this->assertNotNull($turno,
            'Sheets devolvió 500 y el turno no quedó creado. El turno es lo que se cobra: la '
            .'planilla no puede mandar sobre él (AC-22.4).');

        $this->assertSame(Booking::ESTADO_AGENDADO, (string) $turno->status);

        $this->assertTrue($this->alguienDijo('quedó reservado'),
            'El cliente nunca recibió la confirmación: un fallo de la planilla le cortó la '
            .'conversación (AC-13.3).');
    }

    /**
     * **AC-13.3.** El volcado corre **en segundo plano y con reintentos**.
     *
     * Sheets en el camino síncrono del chat le suma su latencia a cada mensaje —
     * y RNF-04 ya está incumplido con las dos llamadas a terceros que hay hoy
     * (2.361 ms de p95 contra un presupuesto de 1.500). Una tercera llamada
     * adentro del camino lo empeora sin necesidad: la planilla puede escribirse
     * un segundo después y a nadie le cambia nada.
     *
     * ⚠️ No se afirma el **nombre** de la clase: se afirma que lo que se encoló
     * es un job encolable y que declara más de un intento, que es lo que
     * «se reintenta» significa.
     */
    public function test_el_volcado_se_encola_y_no_corre_en_el_camino_del_chat(): void
    {
        $this->fakes();
        $this->conPlanilla(self::PLANILLA);
        $this->vincular($this->duenio, self::PLANILLA);

        $pedidosAntes = $this->pedidosASheets();

        Queue::fake();

        $this->llegarHastaElNombre('María Gómez');

        $encolados = [];

        foreach (Queue::pushedJobs() as $clase => $veces) {
            foreach ($veces as $empujado) {
                $encolados[] = $empujado['job'];
            }
        }

        $this->assertNotSame([], $encolados,
            'El lead se volcó sin encolar nada: la escritura en Sheets está en el camino '
            .'síncrono del chat y le suma su latencia a cada mensaje.');

        $this->assertSame($pedidosAntes, $this->pedidosASheets(),
            'Salió una petición a Sheets durante el propio mensaje: el volcado no está '
            .'realmente en segundo plano.');

        $conReintentos = array_values(array_filter(
            $encolados,
            fn ($job) => $job instanceof ShouldQueue && ($this->reintentosDe($job) ?? 1) > 1
        ));

        $this->assertNotSame([], $conReintentos,
            'El job encolado no declara reintentos: un 500 de Sheets pierde el lead para '
            .'siempre y AC-13.3 pide que se reintente. Jobs encolados: '
            .json_encode(array_map('get_class', $encolados)));
    }

    private function reintentosDe(object $job): ?int
    {
        if (method_exists($job, 'tries')) {
            return (int) $job->tries();
        }

        if (property_exists($job, 'tries') && $job->tries !== null) {
            return (int) $job->tries;
        }

        return null;
    }

    // --------------------------------------------------- RNF-01 · aislamiento

    /**
     * **RNF-01.** El lead de una PyME no puede aparecer en la planilla de otra.
     *
     * Es la fuga que la decisión § 11 quiso cerrar mirándola desde el otro lado:
     * ahí se prohíbe compartir planilla; acá se exige que, aun con dos planillas
     * distintas y bien vinculadas, el volcado escriba en la del tenant del
     * mensaje y no en la que encuentre primero.
     */
    public function test_el_lead_de_una_pyme_no_llega_a_la_planilla_de_otra(): void
    {
        $this->fakes();

        [$vecino, $duenioVecino] = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_ID_VECINO);

        $this->conPlanilla(self::PLANILLA);
        $this->conPlanilla(self::PLANILLA_DEL_VECINO);

        $this->vincular($this->duenio, self::PLANILLA);
        $this->vincular($duenioVecino, self::PLANILLA_DEL_VECINO);

        // El mensaje entra por el número del vecino: el lead es suyo.
        $this->llegarHastaElNombre('Roberto Paz', self::TELEFONO_OTRO, self::PHONE_ID_VECINO);

        // Canario: sin esto, los dos «no llegó a la otra» pasan en vacío.
        $this->assertCount(1, $this->escriturasCon(self::PLANILLA_DEL_VECINO, self::TELEFONO_OTRO),
            'El lead no llegó a la planilla de su propia PyME.');

        $this->assertSame([], $this->escriturasEn(self::PLANILLA),
            'Un lead del consultorio se escribió en la planilla de la peluquería: los clientes '
            .'de una PyME quedaron a la vista de otra.');

        // `tenant_id` es UUID: comparar como string. Un `(int)` sobre un UUID
        // devuelve 1 para todos y esta afirmación pasaría siempre.
        $this->assertNotSame((string) $this->tenant->id, (string) $vecino->id,
            'Los dos tenants tienen el mismo id: el escenario no está montado.');
    }

    /**
     * La PyME que todavía no vinculó ninguna planilla **no se rompe**.
     *
     * T-044 es una pantalla que se puede no haber tocado nunca. Si el volcado
     * asumiera que siempre hay planilla, la primera conversación de cada cliente
     * nuevo se caería antes de agendar nada.
     *
     * ⚠️ **Es el único test del archivo que se espera en verde desde el
     * principio**, y se declara acá para no reportarlo como un rojo que no fue:
     * hoy pasa porque no existe una sola línea que llame a Sheets. Su valor es
     * futuro — es la guarda que se pone roja el día que el volcado escriba sin
     * preguntar si hay planilla vinculada.
     */
    public function test_sin_planilla_vinculada_el_flujo_agenda_igual_y_no_se_llama_a_sheets(): void
    {
        $this->fakes();

        $this->llegarHastaElNombre('María Gómez');
        $this->reservarElPrimerHorario();

        $this->assertNotNull($this->turnoDe($this->tenant),
            'Sin planilla vinculada el flujo dejó de agendar.');

        $this->assertSame(0, $this->pedidosASheets(),
            'Se llamó a Sheets sin ninguna planilla vinculada: se está escribiendo en un '
            .'archivo que la PyME no eligió.');
    }

    // ------------------------------------------- AC-26.4 · cambiar de planilla

    /**
     * **AC-26.4.** Cambiada la planilla, los leads nuevos van a la nueva y **la
     * vieja no recibe nada más**.
     *
     * Si siguiera llegando algo a la vieja, la PyME que cambió porque compartió
     * la anterior con alguien externo —el caso real por el que se cambia—
     * seguiría filtrándole clientes.
     */
    public function test_al_cambiar_de_planilla_los_leads_nuevos_van_a_la_nueva(): void
    {
        $this->fakes();
        $this->conPlanilla(self::PLANILLA);
        $this->conPlanilla(self::PLANILLA_NUEVA);

        $this->vincular($this->duenio, self::PLANILLA);
        $this->llegarHastaElNombre('María Gómez');

        $this->assertCount(1, $this->escriturasCon(self::PLANILLA, self::TELEFONO),
            'El escenario no quedó montado: el primer lead no llegó a la planilla vieja.');

        $escriturasViejasAntes = count($this->escriturasEn(self::PLANILLA));

        $this->vincular($this->duenio, self::PLANILLA_NUEVA);

        // Un lead nuevo, de otro teléfono.
        $this->llegarHastaElNombre('Roberto Paz', self::TELEFONO_OTRO);

        $this->assertCount(1, $this->escriturasCon(self::PLANILLA_NUEVA, self::TELEFONO_OTRO),
            'El lead posterior al cambio no llegó a la planilla nueva.');

        $this->assertCount($escriturasViejasAntes, $this->escriturasEn(self::PLANILLA),
            'La planilla vieja recibió escrituras después de que la dueña la desvinculara.');
    }

    /**
     * **AC-26.4.** Cambiar de planilla **no migra** lo ya escrito.
     *
     * El acto de vincular otra planilla es una decisión de configuración, no una
     * mudanza de datos: copiar los leads viejos duplicaría el trabajo comercial
     * del equipo sobre la planilla nueva y expondría en un archivo posiblemente
     * compartido a clientes que estaban en otro.
     */
    public function test_cambiar_de_planilla_no_migra_los_leads_ya_escritos(): void
    {
        $this->fakes();
        $this->conPlanilla(self::PLANILLA);
        $this->conPlanilla(self::PLANILLA_NUEVA);

        $this->vincular($this->duenio, self::PLANILLA);
        $this->llegarHastaElNombre('María Gómez');

        $this->assertCount(1, $this->escriturasCon(self::PLANILLA, self::TELEFONO),
            'El escenario no quedó montado: el lead viejo no existe.');

        $this->vincular($this->duenio, self::PLANILLA_NUEVA);

        $this->assertSame([], $this->escriturasCon(self::PLANILLA_NUEVA, self::TELEFONO),
            'Al vincular la planilla nueva se copiaron los leads que ya estaban escritos en la '
            .'vieja.');
    }

    /**
     * **AC-26.4 · el modo de falla caro del cambio de planilla.**
     *
     * El lead quedó en la fila 2 de la planilla vieja. Si esa referencia
     * sobrevive al cambio, cuando esa persona reserve se va a escribir sobre la
     * **fila 2 de la planilla nueva**, que es de otra persona o de un
     * encabezado. No se pierde un lead: se pisa uno que estaba bien.
     *
     * ⚠️ **Lo que este test no decide:** si el lead viejo tiene que volver a
     * aparecer en la planilla nueva al reservar, o no aparecer en absoluto. El
     * criterio no lo dice y acá no se inventa. Se afirma lo único que las dos
     * salidas comparten: **no se sobrescribe una fila preexistente**.
     */
    public function test_al_cambiar_de_planilla_no_se_pisa_una_fila_de_la_nueva(): void
    {
        $this->fakes();
        $this->conPlanilla(self::PLANILLA);
        $this->conPlanilla(self::PLANILLA_NUEVA);

        $this->vincular($this->duenio, self::PLANILLA);
        $this->llegarHastaElNombre('María Gómez');

        $alta = $this->escriturasCon(self::PLANILLA, self::TELEFONO);
        $this->assertCount(1, $alta, 'El escenario no quedó montado: el lead viejo no existe.');

        $filaVieja = $alta[0]['fila'];
        $this->assertNotNull($filaVieja, 'Google devolvió el rango escrito y nadie lo leyó.');

        $this->vincular($this->duenio, self::PLANILLA_NUEVA);

        $this->reservarElPrimerHorario();

        // Canario: la reserva tiene que haber ocurrido, o no hay nada que volcar.
        $this->assertNotNull($this->turnoDe($this->tenant),
            'No se agendó el turno: el test no ejerció el camino que dice ejercer.');

        foreach ($this->escriturasEn(self::PLANILLA_NUEVA) as $escritura) {
            if ($escritura['append']) {
                continue;
            }

            $this->assertFalse($this->apuntaALaFila($escritura, $filaVieja),
                "Se escribió sobre la fila {$filaVieja} de la planilla nueva, que es la que "
                .'ocupaba el lead en la planilla vieja. Ahí hay datos de otra persona: '
                .$escritura['url']);
        }
    }
}
