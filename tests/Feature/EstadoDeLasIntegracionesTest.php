<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * T-034 · Estado de las integraciones en el panel (US-25).
 *
 * **El dato ya se escribe y nadie construyó dónde leerlo.** T-013 marca
 * `integrations.status = 'expired'` ante un `invalid_grant` y `GoogleTokenRefreshTest`
 * lo cubre: lo que falta es que el dueño lo vea. Sin esto, una credencial vencida
 * se manifiesta como un producto que dejó de funcionar sin explicación, y la
 * primera señal llega por un cliente enojado.
 *
 * ## Contrato que estos tests fijan
 *
 * Ningún criterio de aceptación nombra variables ni claves. Las elijo acá —hace
 * falta para poder escribir el test— y quedan escritas para el implementador:
 *
 * | Dónde | Qué |
 * | :-- | :-- |
 * | `GET /panel` · variable `integraciones` | **Siempre las tres**, indexables por `provider` |
 * | Cada entrada | `provider`, `status`, `account_identifier` |
 * | Compartida en todas las vistas del panel · `avisoIntegraciones` | Lista de avisos; `[]` cuando está todo bien |
 * | Cada aviso | `provider`, `que_se_rompio`, `consecuencia`, `paso_siguiente` |
 *
 * `avisoIntegraciones` tiene que estar **compartida** (un view composer o el
 * middleware del panel), no armada en cada controlador: AC-25.2 pide que se vea
 * *desde cualquier pantalla*, y eso es una propiedad del layout, no de una vista.
 *
 * ## Ambigüedades del criterio que tuve que resolver
 *
 * ⚠️ **Una integración que nunca se configuró no tiene fila.** AC-25.1 pide
 * mostrar las tres —Calendar, Sheets y Meta— pero `integrations` solo tiene fila
 * cuando alguien conectó algo, y en el MVP **Sheets no se conecta desde ningún
 * lado** (T-044 y T-045 están diferidos). Acá se afirma que igual aparece, con
 * `status = 'sin_configurar'` y sin cuenta. La alternativa —listar solo lo que
 * existe— hace que "Sheets no está" y "Sheets está bien" se vean idénticos: la
 * pantalla vacía.
 *
 * ⚠️ **`sin_configurar` no es un valor del ENUM de la base y no debe serlo.** Es
 * la ausencia de fila traducida para la pantalla. Si el implementador lo agrega
 * al ENUM, escribió una migración que este ticket no pide.
 *
 * ⚠️ **`disconnected` no se afirma acá.** AC-25.2 dice *"vencida"* y el ENUM
 * tiene tres valores: `connected`, `disconnected` y `expired`. Si un
 * `disconnected` —que hoy nadie escribe— tiene que avisar igual es una decisión
 * de producto que nadie tomó, y fijarla en un test sería inventarla.
 *
 * ⚠️ **Los desalineados de la conciliación no están acá.** La decisión § 8 de
 * `plan-for-diego/decisiones-tomadas.md` los manda a "una pantalla dentro de
 * T-034", pero hoy `ConciliarAgendamientos` solo emite log estructurado y no hay
 * nada persistido que una pantalla pueda leer. Está explicado en el informe.
 */
class EstadoDeLasIntegracionesTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = '/panel';

    /**
     * **UTC−5 todo el año, sin horario de verano.**
     *
     * Ningún test de este archivo depende de la hora, pero la zona se fija igual
     * y no como `America/Argentina/Buenos_Aires`: en este proyecto ya pasó que un
     * test de husos pasara por casualidad porque Santiago y Buenos Aires comparten
     * offset parte del año (T-031).
     */
    private const TZ = 'America/Bogota';

    /**
     * Códigos técnicos que **no pueden** aparecer en el texto que lee el dueño.
     *
     * AC-25.2 pide *"qué se rompió y qué consecuencia tiene, no un código de
     * error"*. Estos cuatro son los que hoy viajan por el sistema: `invalid_grant`
     * es lo que devuelve Google (T-013), `expired` es el valor de la columna,
     * `401` es el status HTTP y `oauth` el nombre del protocolo.
     */
    private const CODIGOS_TECNICOS = ['invalid_grant', 'expired', '401', 'oauth'];

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ andamiaje

    private function tenant(string $slug = 'piloto'): Tenant
    {
        return Tenant::create([
            'name' => 'Peluquería '.$slug,
            'slug' => $slug.'-'.uniqid(),
            'status' => 'active',
            'timezone' => self::TZ,
        ]);
    }

    private function usuario(Tenant $tenant, Role $rol = Role::Owner): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => $rol->etiqueta(),
            'email' => $rol->value.'-'.uniqid().'@panel.test',
            'password' => 'secreto123',
            'role' => $rol,
        ]);
    }

    private function integracion(
        Tenant $tenant,
        string $provider,
        string $status = 'connected',
        ?string $cuenta = null,
    ): Integration {
        return Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => $provider,
            // El único de `integrations` es (provider, account_identifier): dos
            // tenants no pueden compartirlo ni por accidente en el montaje.
            'account_identifier' => $cuenta ?? $provider.'-'.uniqid(),
            'access_token' => 'ya29',
            'refresh_token' => '1//r',
            'status' => $status,
        ]);
    }

    /**
     * Las integraciones de la vista, indexadas por proveedor.
     *
     * @return array<string,array<string,mixed>>
     */
    private function integracionesDeLaVista(TestResponse $respuesta): array
    {
        $respuesta->assertViewHas('integraciones');

        $lista = $respuesta->viewData('integraciones');
        $lista = is_object($lista) && method_exists($lista, 'all') ? $lista->all() : (array) $lista;

        $out = [];

        foreach ($lista as $entrada) {
            $entrada = (array) $entrada;
            $out[(string) ($entrada['provider'] ?? '')] = $entrada;
        }

        return $out;
    }

    /**
     * Los avisos de integración caída, indexados por proveedor.
     *
     * @return array<string,array<string,mixed>>
     */
    private function avisosDe(TestResponse $respuesta): array
    {
        $respuesta->assertViewHas('avisoIntegraciones');

        $avisos = $respuesta->viewData('avisoIntegraciones');
        $avisos = is_object($avisos) && method_exists($avisos, 'all') ? $avisos->all() : (array) $avisos;

        $out = [];

        foreach ($avisos as $aviso) {
            $aviso = (array) $aviso;
            $out[(string) ($aviso['provider'] ?? '')] = $aviso;
        }

        return $out;
    }

    /** Todo el texto que el dueño llega a leer en un aviso, junto. */
    private function textoDe(array $aviso): string
    {
        return mb_strtolower(implode(' ', [
            $aviso['que_se_rompio'] ?? '',
            $aviso['consecuencia'] ?? '',
            $aviso['paso_siguiente'] ?? '',
        ]));
    }

    // ---------------------------------------------------------- AC-25.1

    /**
     * **AC-25.1 · Las tres integraciones, con su estado y su cuenta.**
     *
     * Por qué importa: el dueño no puede saber que Sheets nunca se conectó si la
     * pantalla solo muestra lo que existe. "No está configurado" y "está todo
     * bien" tienen que verse distinto, o la pantalla no informa nada.
     *
     * El montaje tiene Calendar y Meta con fila y Sheets sin ninguna, que es
     * exactamente el estado de todos los tenants del MVP: T-044 y T-045 están
     * diferidos, así que nadie tiene fila de Sheets.
     */
    public function test_el_panel_muestra_las_tres_integraciones_con_su_estado_y_su_cuenta(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $this->integracion($tenant, Integration::PROVIDER_GOOGLE_CALENDAR,
            cuenta: 'duenio@peluqueria.com');
        $this->integracion($tenant, Integration::PROVIDER_META_WHATSAPP,
            cuenta: '1053554814514902');

        $integraciones = $this->integracionesDeLaVista(
            $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
        );

        $this->assertSame(
            [Integration::PROVIDER_GOOGLE_CALENDAR, Integration::PROVIDER_GOOGLE_SHEETS, Integration::PROVIDER_META_WHATSAPP],
            collect(array_keys($integraciones))->sort()->values()->all(),
            'El panel no muestra las tres integraciones: falta alguna, o aparece una que no es.',
        );

        $this->assertSame('connected', $integraciones[Integration::PROVIDER_GOOGLE_CALENDAR]['status'],
            'El estado de Google Calendar no llega a la pantalla.');
        $this->assertSame('duenio@peluqueria.com', $integraciones[Integration::PROVIDER_GOOGLE_CALENDAR]['account_identifier'],
            'No se ve con qué cuenta quedó vinculado el calendario: el dueño no puede saber si es la correcta.');

        $this->assertSame('connected', $integraciones[Integration::PROVIDER_META_WHATSAPP]['status'],
            'El estado de WhatsApp no llega a la pantalla.');
        $this->assertSame('1053554814514902', $integraciones[Integration::PROVIDER_META_WHATSAPP]['account_identifier'],
            'No se ve qué número de WhatsApp está vinculado.');

        $this->assertSame('sin_configurar', $integraciones[Integration::PROVIDER_GOOGLE_SHEETS]['status'],
            'Google Sheets no aparece como "sin configurar": una integración que nunca se conectó se ve igual que una sana.');
        $this->assertNull($integraciones[Integration::PROVIDER_GOOGLE_SHEETS]['account_identifier'],
            'Google Sheets, que no está conectado, aparece con una cuenta vinculada.');
    }

    /**
     * **RNF-01 · El estado de una PyME no se ve desde el panel de otra.**
     *
     * Por qué importa: `Integration` es la única tabla de negocio **sin** Global
     * Scope —la ingesta resuelve el tenant por `phone_number_id`—, así que acá el
     * aislamiento depende de que la consulta filtre a mano. Es justo la forma en
     * la que se filtra información en silencio.
     *
     * La comparación es **como string**: `tenant_id` es UUID y un `(int)` sobre
     * un UUID devuelve `1` para todos, con lo que el test pasaría con la fuga
     * abierta.
     *
     * ⚠️ **Este test nació verde y lo digo con todas las letras: no es un rojo de
     * TDD, es una guarda de regresión.** `routes/web.php` ya filtra con un
     * `where('tenant_id', $tenant->id)` explícito, y el comentario que lo
     * acompaña deja claro que fue deliberado. Queda escrito porque T-034 va a
     * reescribir esa consulta —tiene que devolver las tres integraciones, no las
     * que existan— y ahí es donde el filtro se puede perder sin que nada avise.
     */
    public function test_el_panel_de_una_pyme_no_muestra_las_integraciones_de_otra(): void
    {
        $mia = $this->tenant('mia');
        $ajena = $this->tenant('ajena');

        $usuario = $this->usuario($mia);

        $this->integracion($mia, Integration::PROVIDER_GOOGLE_CALENDAR, cuenta: 'yo@mia.com');
        $this->integracion($ajena, Integration::PROVIDER_GOOGLE_CALENDAR, cuenta: 'otro@ajena.com');
        $this->integracion($ajena, Integration::PROVIDER_META_WHATSAPP, cuenta: '999888777');

        $integraciones = $this->integracionesDeLaVista(
            $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
        );

        $cuentas = array_map(
            fn (array $i) => (string) ($i['account_identifier'] ?? ''),
            $integraciones,
        );

        $this->assertContains('yo@mia.com', $cuentas,
            'El panel no muestra la propia cuenta vinculada.');
        $this->assertNotContains('otro@ajena.com', $cuentas,
            'Se filtró la cuenta de Google de otra PyME al panel.');
        $this->assertNotContains('999888777', $cuentas,
            'Se filtró el número de WhatsApp de otra PyME al panel.');
    }

    // ---------------------------------------------------------- AC-25.2

    /**
     * **AC-25.2 · El aviso dice qué se rompió y qué consecuencia tiene.**
     *
     * Por qué importa: el dueño no sabe qué es un `invalid_grant` ni tiene por
     * qué. Lo que necesita saber es que **el bot dejó de poder agendar turnos**,
     * porque eso es lo que le está costando plata mientras no lo arregle.
     */
    public function test_una_integracion_vencida_avisa_que_se_rompio_y_que_consecuencia_tiene(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $this->integracion($tenant, Integration::PROVIDER_GOOGLE_CALENDAR,
            status: 'expired', cuenta: 'duenio@peluqueria.com');
        $this->integracion($tenant, Integration::PROVIDER_META_WHATSAPP);

        $avisos = $this->avisosDe(
            $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
        );

        $this->assertArrayHasKey(Integration::PROVIDER_GOOGLE_CALENDAR, $avisos,
            'Google Calendar está vencido y el panel no avisa nada: el producto dejó de agendar sin decirlo.');

        $aviso = $avisos[Integration::PROVIDER_GOOGLE_CALENDAR];

        $this->assertNotEmpty(trim((string) ($aviso['que_se_rompio'] ?? '')),
            'El aviso no dice qué se rompió.');
        $this->assertNotEmpty(trim((string) ($aviso['consecuencia'] ?? '')),
            'El aviso no dice qué consecuencia tiene: un rojo sin explicación obliga a llamar a soporte.');

        $this->assertMatchesRegularExpression(
            '/turno/i',
            (string) $aviso['consecuencia'],
            'La consecuencia de perder el calendario no menciona los turnos, que es lo único que le importa al dueño.',
        );

        $texto = $this->textoDe($aviso);

        foreach (self::CODIGOS_TECNICOS as $codigo) {
            $this->assertStringNotContainsString($codigo, $texto,
                "El aviso le muestra al dueño el código técnico «{$codigo}» en vez de explicarle qué pasó.");
        }

        $this->assertArrayNotHasKey(Integration::PROVIDER_META_WHATSAPP, $avisos,
            'Se avisa de WhatsApp, que está conectado: un aviso que aparece sin motivo se aprende a ignorar.');
    }

    /**
     * **AC-25.2 · Cada integración explica su propia consecuencia.**
     *
     * Por qué importa: un aviso genérico —*"hay un problema con una
     * integración"*— no le dice al dueño si dejó de vender turnos o si dejó de
     * llenarse una planilla que mira una vez por semana. Son urgencias distintas
     * y el aviso tiene que separarlas.
     *
     * El test afirma además que los tres textos son **distintos entre sí**: es lo
     * que impide resolver el criterio con una plantilla única y el nombre del
     * proveedor interpolado.
     */
    public function test_cada_integracion_caida_explica_su_propia_consecuencia(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $this->integracion($tenant, Integration::PROVIDER_GOOGLE_CALENDAR, status: 'expired');
        $this->integracion($tenant, Integration::PROVIDER_GOOGLE_SHEETS, status: 'expired');
        $this->integracion($tenant, Integration::PROVIDER_META_WHATSAPP, status: 'expired');

        $avisos = $this->avisosDe(
            $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
        );

        $this->assertCount(3, $avisos,
            'Con las tres integraciones vencidas el panel no avisa de las tres.');

        $this->assertMatchesRegularExpression('/turno/i',
            (string) $avisos[Integration::PROVIDER_GOOGLE_CALENDAR]['consecuencia'],
            'Sin calendario no se pueden ofrecer ni reservar turnos, y el aviso no lo dice.');

        $this->assertMatchesRegularExpression('/whatsapp|mensaje/i',
            (string) $avisos[Integration::PROVIDER_META_WHATSAPP]['consecuencia'],
            'Sin WhatsApp el bot no le puede contestar a nadie, y el aviso no lo dice.');

        $this->assertMatchesRegularExpression('/planilla|sheets/i',
            (string) $avisos[Integration::PROVIDER_GOOGLE_SHEETS]['consecuencia'],
            'Sin Sheets los datos no se vuelcan a la planilla, y el aviso no lo dice.');

        $consecuencias = array_map(fn (array $a) => (string) $a['consecuencia'], $avisos);

        $this->assertCount(3, array_unique($consecuencias),
            'Las tres integraciones caídas muestran la misma consecuencia: el aviso es genérico y no informa nada.');
    }

    /**
     * **AC-25.2 · El aviso se ve desde cualquier pantalla.**
     *
     * Por qué importa: el dueño entra al panel a marcar asistencia o a mirar una
     * conversación, no a revisar integraciones. Si el aviso vive solo en la
     * pantalla de integraciones, se entera cuando ya fue.
     *
     * Es lo que obliga a compartir la variable en el layout en vez de armarla en
     * cada controlador — que además es la forma en la que la próxima pantalla
     * nace sin el aviso.
     */
    public function test_el_aviso_de_la_integracion_caida_se_ve_desde_cualquier_pantalla(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $this->integracion($tenant, Integration::PROVIDER_GOOGLE_CALENDAR, status: 'expired');

        foreach (['/panel', '/panel/asistencia', '/panel/conversaciones'] as $pantalla) {
            $avisos = $this->avisosDe(
                $this->actingAs($usuario)->get($pantalla)->assertSuccessful(),
            );

            $this->assertArrayHasKey(Integration::PROVIDER_GOOGLE_CALENDAR, $avisos,
                "En «{$pantalla}» no se ve el aviso de que el calendario está caído.");
        }
    }

    // ---------------------------------------------------------- AC-25.4

    /**
     * **AC-25.4 · El aviso dice el paso siguiente, y hoy ese paso es contactarnos.**
     *
     * Por qué importa: la reconexión autoservicio es v2 y está fuera de alcance
     * del ticket. Un aviso que ofrece un botón que no existe es peor que no
     * avisar: el dueño toca, no pasa nada, y deja de confiar en la pantalla.
     *
     * ⚠️ El texto sale de las notas del ticket, no de mi cabeza: *"el texto de
     * AC-25.4 tiene que decir «contactanos» con todas las letras"*.
     */
    public function test_el_aviso_dice_el_paso_siguiente_y_en_el_mvp_es_contactarnos(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $this->integracion($tenant, Integration::PROVIDER_GOOGLE_CALENDAR, status: 'expired');

        $avisos = $this->avisosDe(
            $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful(),
        );

        $paso = (string) ($avisos[Integration::PROVIDER_GOOGLE_CALENDAR]['paso_siguiente'] ?? '');

        $this->assertNotEmpty(trim($paso),
            'El aviso no dice qué hacer: el dueño ve que algo se rompió y no tiene siguiente paso.');
        $this->assertMatchesRegularExpression('/contact/i', $paso,
            'El paso siguiente no es contactarnos, y en el MVP la reconexión la hacemos nosotros: '
            ."el aviso dice «{$paso}».");
    }

    // ---------------------------------------------------------- AC-25.3

    /**
     * **AC-25.3 · Al volver a funcionar, el aviso desaparece solo.**
     *
     * Por qué importa: si el aviso hay que apagarlo a mano, queda prendido para
     * siempre y el dueño aprende a ignorarlo. Después, el día que se rompe algo
     * de verdad, no lo mira.
     *
     * El montaje simula lo que hace T-013 cuando el refresh vuelve a andar:
     * escribe `connected` en la columna y **nada más**. Que el aviso se apague
     * solo es consecuencia de derivarlo del estado y no de guardar un aviso.
     *
     * La primera aserción es el **canario**: sin ella, un panel que no avisa
     * nunca pasaría este test.
     */
    public function test_cuando_la_integracion_vuelve_a_funcionar_el_aviso_desaparece_solo(): void
    {
        $tenant = $this->tenant();
        $usuario = $this->usuario($tenant);

        $calendario = $this->integracion($tenant, Integration::PROVIDER_GOOGLE_CALENDAR, status: 'expired');

        $this->assertArrayHasKey(
            Integration::PROVIDER_GOOGLE_CALENDAR,
            $this->avisosDe($this->actingAs($usuario)->get(self::PANEL)->assertSuccessful()),
            'Canario: el panel no avisó de la integración vencida, así que la segunda mitad del test no probaría nada.',
        );

        // Lo que escribe T-013 al renovar bien el token: solo la columna.
        DB::table('integrations')->where('id', $calendario->id)->update(['status' => 'connected']);

        $respuesta = $this->actingAs($usuario)->get(self::PANEL)->assertSuccessful();

        $this->assertSame([], $this->avisosDe($respuesta),
            'La integración volvió a funcionar y el aviso sigue prendido: hay que apagarlo a mano.');

        $this->assertSame(
            'connected',
            $this->integracionesDeLaVista($respuesta)[Integration::PROVIDER_GOOGLE_CALENDAR]['status'],
            'La sección de integraciones sigue mostrando el estado viejo.',
        );
    }

    /**
     * **RNF-01 · El aviso de una PyME no se ve en el panel de otra.**
     *
     * Por qué importa: un aviso cruzado le dice al dueño B que su calendario se
     * rompió cuando el roto es el de A. B llama, no encuentra nada, y pierde la
     * confianza en el único canal que tenemos para avisarle de verdad.
     *
     * Va con canario: primero se verifica que A **sí** ve su aviso, porque si no
     * el "B no lo ve" sería cierto en un panel que no avisa nunca.
     */
    public function test_el_aviso_de_una_pyme_no_aparece_en_el_panel_de_otra(): void
    {
        $rota = $this->tenant('rota');
        $sana = $this->tenant('sana');

        $this->integracion($rota, Integration::PROVIDER_GOOGLE_CALENDAR, status: 'expired');
        $this->integracion($sana, Integration::PROVIDER_GOOGLE_CALENDAR, status: 'connected');

        $this->assertNotEmpty(
            $this->avisosDe($this->actingAs($this->usuario($rota))->get(self::PANEL)->assertSuccessful()),
            'Canario: la PyME con la integración vencida no vio ningún aviso.',
        );

        $this->assertSame(
            [],
            $this->avisosDe($this->actingAs($this->usuario($sana))->get(self::PANEL)->assertSuccessful()),
            'Una PyME ve el aviso de una integración caída de otra PyME.',
        );
    }
}
