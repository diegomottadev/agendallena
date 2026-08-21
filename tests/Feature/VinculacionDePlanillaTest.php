<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-044 · US-26 · La PyME elige **cuál** de sus planillas recibe los leads.
 *
 * Sin esto, el volcado de T-045 técnicamente funciona y comercialmente no
 * cumple: los leads caen en un archivo que creamos nosotros, que es exactamente
 * el CRM nuevo que la PyME ya rechazó. La mitad de la propuesta de valor es
 * *"sin obligarte a cambiar de herramientas"*, y esa mitad se juega acá.
 *
 * ## Lo que este archivo decide, porque el criterio no lo decía
 *
 * ⚠️ **La superficie.** Los criterios hablan de "indicar la planilla" sin decir
 * por dónde. Se asume el panel, con la convención que ya tienen T-015 y T-017:
 * `GET/PUT /panel/configuracion/planilla` detrás de `rol:configurar`, más un
 * `POST .../crear` para AC-26.2. Si se prefiere otra ruta, es un rename.
 *
 * ⚠️ **El nombre de la columna.** La decisión § 11 dice, textual, *"un único
 * sobre el `spreadsheet_id`"*. Los tests buscan **una columna llamada
 * `spreadsheet_id`** en cualquier tabla del esquema: puede vivir en
 * `integrations` o en una tabla propia, eso queda abierto. Lo que no queda
 * abierto es que exista y que esté indexada única.
 *
 * ⚠️ **Lo que NO se afirma:** el texto exacto de los mensajes de error, ni las
 * etiquetas exactas de los encabezados. Se afirma que el mensaje de "sin
 * permiso" y el de "no existe" **son distintos** —que es lo que pide distinguir
 * uno de otro— y que los encabezados nombran las cinco columnas de AC-13.1 más
 * las dos que agrega AC-13.2.
 */
class VinculacionDePlanillaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/panel/configuracion/planilla';

    private const TZ = 'America/Argentina/Buenos_Aires';

    /** La planilla que el dueño ya usa. El `id` es el formato real de Google. */
    private const PLANILLA_PROPIA = '1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms';

    private const PLANILLA_AJENA = '1ZfTkOaXwHYtNbmXpQrSvWxYz0123456789AbCdEfGh';

    private Tenant $tenant;

    private User $duenio;

    /**
     * Las planillas que existen del lado de Google, y con qué permiso.
     *
     * Es el estado que hace que el doble se parezca a Sheets. Sin él, el test
     * decidiría cuándo hay error en vez de descubrirlo.
     *
     * @var array<string,string> `id => 'ok' | 'sin_permiso'`
     */
    private array $planillasEnGoogle = [];

    private int $creadas = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 08:00', self::TZ));

        $this->tenant = Tenant::create([
            'name' => 'Peluquería Sur', 'slug' => 'piloto',
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $this->duenio = $this->usuarioDe($this->tenant, Role::Owner);

        // El consentimiento de Google ya incluye el scope `spreadsheets`
        // (config/services.php), así que no hay que re-autorizar a nadie: la
        // vinculación usa el token que dejó T-012.
        $this->conectarGoogle($this->tenant, 'duenio@peluqueria.com');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------- utilidades

    private function usuarioDe(Tenant $tenant, Role $rol): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $rol->etiqueta(),
            'email' => $rol->value.'@'.$tenant->slug.'.test',
            'password' => 'secreto123',
            'role' => $rol,
        ]);
    }

    private function conectarGoogle(Tenant $tenant, string $cuenta): Integration
    {
        return Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => $cuenta,
            'access_token' => 'ya29.'.$tenant->slug,
            'refresh_token' => '1//r-'.$tenant->slug,
            'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);
    }

    private function urlDe(string $spreadsheetId): string
    {
        return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit#gid=0";
    }

    // ---------------------------------------------------------------- el doble

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` **acumula** stubs: un segundo `fake()` no reemplaza al
     * primero y el doble que simula el error nunca se consume. Ya hizo pasar
     * tres tests falsos en T-026. Por eso Sheets entera —metadata, creación y
     * escritura— entra en un único mapa y se comporta según su propio estado.
     *
     * @param  array<string,string>  $planillas  `id => 'ok' | 'sin_permiso'`.
     *                                           Las que no figuran **no existen**, y eso es lo que distingue AC-26.3.
     */
    private function fakeSheets(array $planillas): void
    {
        $this->planillasEnGoogle = $planillas;

        Http::fake([
            'sheets.googleapis.com/*' => function (Request $request) {
                $ruta = (string) parse_url($request->url(), PHP_URL_PATH);

                // `spreadsheets.create`: no lleva `id` en la ruta.
                if ($request->method() === 'POST' && rtrim($ruta, '/') === '/v4/spreadsheets') {
                    $id = 'nueva_planilla_'.(++$this->creadas);
                    $this->planillasEnGoogle[$id] = 'ok';

                    return Http::response([
                        'spreadsheetId' => $id,
                        'spreadsheetUrl' => $this->urlDe($id),
                        'properties' => ['title' => 'Leads · AgendaLlena'],
                        'sheets' => [['properties' => ['sheetId' => 0, 'title' => 'Leads']]],
                    ]);
                }

                $id = $this->idDeLaRuta($ruta);
                $permiso = $this->planillasEnGoogle[$id] ?? null;

                if ($permiso === null) {
                    return Http::response([
                        'error' => [
                            'code' => 404,
                            'message' => 'Requested entity was not found.',
                            'status' => 'NOT_FOUND',
                        ],
                    ], 404);
                }

                if ($permiso === 'sin_permiso') {
                    return Http::response([
                        'error' => [
                            'code' => 403,
                            'message' => 'The caller does not have permission',
                            'status' => 'PERMISSION_DENIED',
                            'errors' => [['reason' => 'forbidden']],
                        ],
                    ], 403);
                }

                // Lectura de metadata: las hojas que tiene el archivo.
                if ($request->method() === 'GET') {
                    return Http::response([
                        'spreadsheetId' => $id,
                        'properties' => ['title' => 'Seguimiento comercial'],
                        'sheets' => [
                            ['properties' => ['sheetId' => 0, 'title' => 'Hoja 1']],
                            ['properties' => ['sheetId' => 71, 'title' => 'Leads']],
                            ['properties' => ['sheetId' => 88, 'title' => 'Prospectos']],
                        ],
                    ]);
                }

                // Escritura: append, update, batchUpdate o la fila de prueba.
                return Http::response([
                    'spreadsheetId' => $id,
                    'updates' => [
                        'spreadsheetId' => $id,
                        'updatedRange' => 'Leads!A2:G2',
                        'updatedRows' => 1,
                    ],
                ]);
            },
        ]);
    }

    private function idDeLaRuta(string $ruta): string
    {
        if (preg_match('#/v4/spreadsheets/([^/:?]+)#', $ruta, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * Las peticiones que salieron hacia Sheets.
     *
     * @return array<int,Request>
     */
    private function pedidosASheets(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'sheets.googleapis.com')) {
                $out[] = $req;
            }
        }

        return $out;
    }

    private function huboPedidoSobre(string $spreadsheetId): bool
    {
        foreach ($this->pedidosASheets() as $req) {
            if (str_contains($req->url(), $spreadsheetId)) {
                return true;
            }
        }

        return false;
    }

    /** Todo lo que se mandó a Sheets, en un solo texto, para buscar dentro. */
    private function todoLoEnviadoASheets(): string
    {
        $todo = '';

        foreach ($this->pedidosASheets() as $req) {
            $todo .= ' '.$req->url().' '.json_encode($req->data(), JSON_UNESCAPED_UNICODE);
        }

        return $todo;
    }

    // -------------------------------------------------- dónde quedó la vinculación

    /**
     * Las tablas del esquema que tienen una columna `spreadsheet_id`.
     *
     * La vinculación puede vivir en `integrations` o en una tabla propia: eso lo
     * decide quien implemente. Lo que el test necesita saber es **dónde quedó**,
     * y lo averigua del esquema en vez de suponerlo.
     *
     * @return array<int,string>
     */
    private function tablasDePlanilla(): array
    {
        $filas = DB::select(
            'SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?',
            ['spreadsheet_id']
        );

        return array_map(fn ($f) => (string) $f->t, $filas);
    }

    /**
     * Las planillas vinculadas a ese tenant.
     *
     * ⚠️ El filtro va por `tenant_id` **como string**: es UUID, y un `(int)`
     * sobre un UUID devuelve `1` para todos — con eso, un test de aislamiento
     * pasa siempre, incluso con la fuga presente.
     *
     * @return array<int,string> Los `spreadsheet_id` vinculados.
     */
    private function planillasDe(Tenant $tenant): array
    {
        $out = [];

        foreach ($this->tablasDePlanilla() as $tabla) {
            $filas = DB::table($tabla)
                ->where('tenant_id', (string) $tenant->id)
                ->whereNotNull('spreadsheet_id')
                ->where('spreadsheet_id', '!=', '')
                ->pluck('spreadsheet_id');

            foreach ($filas as $id) {
                $out[] = (string) $id;
            }
        }

        return $out;
    }

    private function planillaDe(Tenant $tenant): ?string
    {
        $todas = $this->planillasDe($tenant);

        $this->assertLessThanOrEqual(1, count($todas),
            'El tenant quedó con más de una planilla vinculada: el volcado no tendría forma '
            .'de saber a cuál escribir.');

        return $todas[0] ?? null;
    }

    // ------------------------------------------------- AC-26.1 · vincular la propia

    /**
     * **AC-26.1.** La dueña pega la URL de su planilla, elige la hoja, y el
     * sistema le confirma que puede escribir ahí.
     *
     * Confirmar sin haber probado el acceso es la peor de las salidas: la PyME
     * se va tranquila y se entera de que no funcionaba el día que busca un lead
     * y no está. Por eso el test exige la ida a Google **además** de la fila.
     */
    public function test_indicada_la_planilla_y_la_hoja_se_valida_el_acceso_y_se_confirma(): void
    {
        $this->fakeSheets([self::PLANILLA_PROPIA => 'ok']);

        $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_PROPIA), 'hoja' => 'Leads'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::URL);

        $this->assertTrue($this->huboPedidoSobre(self::PLANILLA_PROPIA),
            'Se dio la vinculación por buena sin preguntarle nada a Google: nadie comprobó '
            .'que se pueda escribir en esa planilla.');

        $this->assertSame(self::PLANILLA_PROPIA, $this->planillaDe($this->tenant),
            'La planilla no quedó vinculada al tenant.');
    }

    /**
     * **AC-26.1.** Confirmado significa que la dueña lo ve, no que lo sepa la base.
     *
     * La pantalla tiene que devolverle cuál planilla y cuál hoja quedaron: es lo
     * único que le permite darse cuenta de que pegó la URL equivocada.
     */
    public function test_la_pantalla_muestra_que_planilla_y_que_hoja_quedaron_vinculadas(): void
    {
        $this->fakeSheets([self::PLANILLA_PROPIA => 'ok']);

        $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_PROPIA), 'hoja' => 'Prospectos']);

        $this->actingAs($this->duenio)
            ->get(self::URL)
            ->assertOk()
            ->assertSee(self::PLANILLA_PROPIA)
            ->assertSee('Prospectos');
    }

    /** Configurar integraciones es de `owner` y `admin`, como el resto del panel. */
    public function test_quien_solo_atiende_no_puede_cambiar_la_planilla(): void
    {
        $this->fakeSheets([self::PLANILLA_PROPIA => 'ok']);

        $empleado = $this->usuarioDe($this->tenant, Role::Staff);

        $this->actingAs($empleado)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_PROPIA), 'hoja' => 'Leads'])
            ->assertForbidden();

        $this->assertNull($this->planillaDe($this->tenant),
            'Un rol sin permiso de configuración dejó la planilla vinculada igual.');
    }

    // --------------------------------------------- AC-26.2 · empezar sin planilla

    /**
     * **AC-26.2.** La PyME que no tiene ninguna planilla pide que se le cree una.
     *
     * Es la mitad del KPI de setup: si el único camino fuera "andá a Drive,
     * creala, copiá la URL", la vinculación se abandona en el paso dos.
     */
    public function test_sin_planilla_propia_se_crea_una_y_queda_vinculada(): void
    {
        $this->fakeSheets([]);

        $this->actingAs($this->duenio)
            ->post(self::URL.'/crear', ['nombre' => 'Leads · Peluquería Sur'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::URL);

        $vinculada = $this->planillaDe($this->tenant);

        $this->assertNotNull($vinculada, 'Se pidió crear la planilla y no quedó ninguna vinculada.');
        $this->assertSame('nueva_planilla_1', $vinculada,
            'Quedó vinculada una planilla que no es la que Google dijo haber creado.');
    }

    /**
     * **AC-26.2.** La planilla nueva nace con las columnas correspondientes.
     *
     * Una planilla en blanco no es "la planilla donde el equipo trabaja": sin
     * encabezados, la primera fila de un lead es cinco datos sueltos que nadie
     * sabe leer. Las columnas son las cinco de AC-13.1 más las dos que agrega
     * AC-13.2 al reservar.
     *
     * ⚠️ Se afirma **qué nombra cada columna**, no la etiqueta exacta: el test
     * busca la palabra, no el texto completo.
     */
    public function test_la_planilla_creada_lleva_los_encabezados_de_las_columnas(): void
    {
        $this->fakeSheets([]);

        $this->actingAs($this->duenio)->post(self::URL.'/crear', ['nombre' => 'Leads']);

        $enviado = mb_strtolower($this->todoLoEnviadoASheets());

        foreach (['fecha', 'nombre', 'monto', 'estado', 'turno', 'evento'] as $columna) {
            $this->assertStringContainsString($columna, $enviado,
                "La planilla creada no tiene ninguna columna que nombre «{$columna}»: el lead "
                .'va a caer en una grilla sin encabezados.');
        }

        // El acento no puede decidir si el test pasa.
        $this->assertTrue(
            str_contains($enviado, 'tel') && str_contains($enviado, 'fono'),
            'La planilla creada no tiene columna de teléfono, que es el único dato con el que '
            .'la PyME puede volver a contactar al lead.'
        );
    }

    // --------------------------------------------- AC-26.3 · permisos insuficientes

    /**
     * **AC-26.3.** Sin permiso de escritura, la vinculación **no queda a medias**.
     *
     * Una fila guardada sobre una planilla que no se puede escribir es peor que
     * no tener nada: el panel muestra la integración sana y los leads se pierden
     * en silencio, que es el mismo modo de falla que T-034 vino a cerrar en las
     * integraciones de Google.
     */
    public function test_sin_permiso_de_escritura_se_explica_el_problema_y_no_queda_vinculada(): void
    {
        $this->fakeSheets([self::PLANILLA_PROPIA => 'sin_permiso']);

        $respuesta = $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_PROPIA), 'hoja' => 'Leads']);

        $respuesta->assertSessionHasErrors();

        $this->assertNull($this->planillaDe($this->tenant),
            'Falló la validación de permisos y la planilla quedó vinculada igual: el panel va '
            .'a mostrar una integración sana mientras los leads se pierden.');

        $mensaje = mb_strtolower(implode(' ', session('errors')->all()));

        $this->assertStringContainsString('permis', $mensaje,
            'El mensaje no nombra el problema. La dueña no tiene forma de saber que lo que '
            .'falta es compartir la planilla.');
    }

    /**
     * **AC-26.3.** "No existe" y "no tenés acceso" no son el mismo problema.
     *
     * Se resuelven distinto: una es revisar la URL, la otra es compartir el
     * archivo. Un mensaje único obliga a la dueña a probar las dos, y es la
     * clase de fricción que hace abandonar el setup.
     *
     * ⚠️ El test **no fija el texto**: afirma que los dos mensajes difieren, que
     * es lo único que "distinguir" puede significar sin inventar la redacción.
     */
    public function test_una_planilla_inexistente_da_un_mensaje_distinto_al_de_sin_permiso(): void
    {
        $this->fakeSheets([self::PLANILLA_PROPIA => 'sin_permiso']);

        $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_PROPIA), 'hoja' => 'Leads'])
            ->assertSessionHasErrors();

        $porPermiso = implode(' ', session('errors')->all());

        $this->flushSession();

        // Esta no figura en el doble: para Google no existe.
        $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe('planilla_que_no_existe'), 'hoja' => 'Leads'])
            ->assertSessionHasErrors();

        $porInexistente = implode(' ', session('errors')->all());

        $this->assertNotSame('', trim($porInexistente), 'La planilla inexistente no dio mensaje.');

        $this->assertNotSame($porPermiso, $porInexistente,
            'Los dos errores dicen exactamente lo mismo: la dueña no puede saber si tiene que '
            .'revisar la URL o compartir el archivo.');

        $this->assertNull($this->planillaDe($this->tenant),
            'Una planilla que Google dice que no existe quedó vinculada.');
    }

    /**
     * **AC-26.3 · "no queda a medias"**, en su forma más cara.
     *
     * La PyME ya tenía su planilla andando y prueba con otra que no tiene
     * compartida. Si el fallo pisa la vinculación anterior, el volcado se rompe
     * para una PyME que no cambió nada: no perdió el cambio, perdió lo que ya
     * funcionaba.
     */
    public function test_un_fallo_de_permisos_no_pisa_la_planilla_que_ya_estaba_vinculada(): void
    {
        $this->fakeSheets([
            self::PLANILLA_PROPIA => 'ok',
            self::PLANILLA_AJENA => 'sin_permiso',
        ]);

        $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_PROPIA), 'hoja' => 'Leads'])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::PLANILLA_PROPIA, $this->planillaDe($this->tenant),
            'El escenario no quedó montado: la primera vinculación tenía que funcionar.');

        $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_AJENA), 'hoja' => 'Leads'])
            ->assertSessionHasErrors();

        $this->assertSame(self::PLANILLA_PROPIA, $this->planillaDe($this->tenant),
            'Un intento fallido dejó al tenant sin la planilla que ya tenía andando.');
    }

    // ------------------------------------------------- AC-26.4 · cambiar de planilla

    /**
     * **AC-26.4.** Cambiar de planilla deja **una** vinculación, la nueva.
     *
     * Si quedaran las dos, el volcado escribiría en la que encuentre primero —y
     * "primero" lo decide el orden de las filas, que no es una decisión de
     * producto.
     *
     * ⚠️ Que los leads nuevos vayan efectivamente a la nueva y que la vieja no
     * reciba nada se afirma en `VolcadoDeLeadsASheetsTest`, que es donde existe
     * el volcado.
     */
    public function test_al_cambiar_de_planilla_queda_vinculada_solo_la_nueva(): void
    {
        $this->fakeSheets([
            self::PLANILLA_PROPIA => 'ok',
            self::PLANILLA_AJENA => 'ok',
        ]);

        $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_PROPIA), 'hoja' => 'Leads']);

        $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_AJENA), 'hoja' => 'Leads'])
            ->assertSessionHasNoErrors();

        $this->assertSame([self::PLANILLA_AJENA], $this->planillasDe($this->tenant),
            'Después del cambio el tenant quedó con más de una planilla vinculada, o con la '
            .'vieja.');
    }

    // ------------------------------- decisión § 11 · una planilla, un solo negocio

    /**
     * **Decisión § 11.** Dos negocios **no** pueden usar la misma planilla.
     *
     * No es una regla de higiene: los clientes de una PyME quedarían mezclados
     * con los de otra en el mismo archivo, que es una fuga de datos entre
     * clientes y choca de frente con RNF-01.
     */
    public function test_dos_negocios_no_pueden_vincular_la_misma_planilla(): void
    {
        $this->fakeSheets([self::PLANILLA_PROPIA => 'ok']);

        $otra = Tenant::create([
            'name' => 'Consultorio Norte', 'slug' => 'consultorio',
            'status' => 'active', 'timezone' => self::TZ,
        ]);
        $this->conectarGoogle($otra, 'duenio@consultorio.com');
        $vecino = $this->usuarioDe($otra, Role::Owner);

        $this->actingAs($this->duenio)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_PROPIA), 'hoja' => 'Leads'])
            ->assertSessionHasNoErrors();

        $this->flushSession();

        $this->actingAs($vecino)
            ->put(self::URL, ['url' => $this->urlDe(self::PLANILLA_PROPIA), 'hoja' => 'Leads'])
            ->assertSessionHasErrors();

        $this->assertNull($this->planillaDe($otra),
            'El segundo negocio quedó vinculado a la planilla del primero: sus leads y los del '
            .'vecino van a terminar en el mismo archivo.');

        // Canario: el primero no puede haber perdido la suya en el intento.
        $this->assertSame(self::PLANILLA_PROPIA, $this->planillaDe($this->tenant),
            'El intento del vecino se llevó puesta la vinculación del dueño original.');
    }

    /**
     * **Decisión § 11 · el candado va en el esquema, no en un `if`.**
     *
     * Es la lección de `live_event_id`: una comprobación en PHP deja la ventana
     * de carrera abierta entre dos workers, y dos vinculaciones simultáneas
     * pasan las dos. La garantía tiene que ser del motor.
     *
     * ⚠️ **El único tiene que ser sobre `spreadsheet_id` solo.** Un
     * `(tenant_id, spreadsheet_id)` se ve parecido y permite exactamente lo que
     * la decisión prohíbe: dos tenants distintos sobre la misma planilla.
     */
    public function test_el_candado_de_la_planilla_esta_en_el_esquema(): void
    {
        $unicos = DB::select(
            'SELECT TABLE_NAME AS t, INDEX_NAME AS i, '
            ."GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols "
            .'FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 '
            .'GROUP BY TABLE_NAME, INDEX_NAME'
        );

        $sobreLaPlanilla = [];

        foreach ($unicos as $indice) {
            $columnas = explode(',', (string) $indice->cols);

            if (in_array('spreadsheet_id', $columnas, true)) {
                $sobreLaPlanilla[$indice->t.'.'.$indice->i] = $columnas;
            }
        }

        $this->assertNotSame([], $sobreLaPlanilla,
            'No hay ningún índice único que toque `spreadsheet_id`: nada a nivel base impide '
            .'que dos negocios vinculen la misma planilla.');

        $this->assertContains(['spreadsheet_id'], array_values($sobreLaPlanilla),
            'Hay un único que incluye `spreadsheet_id` pero acompañado de otra columna. Si '
            .'esa columna es `tenant_id`, el único permite justo lo que la decisión § 11 '
            .'prohíbe: la misma planilla en dos negocios. Índices encontrados: '
            .json_encode($sobreLaPlanilla));
    }
}
