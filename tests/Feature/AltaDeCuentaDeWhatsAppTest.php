<?php

namespace Tests\Feature;

use App\Meta\AltaDeCuenta;
use App\Meta\PlantillasDelTenant;
use App\Meta\SinCuentaDeWhatsApp;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-050 · Cada PyME tiene su propia cuenta de WhatsApp, y sus propias plantillas.
 *
 * Decidido el 2026-08-20: **una WABA por PyME**, no una cuenta del proveedor con
 * muchos números adentro. La consecuencia que este ticket existe para resolver es
 * que **las plantillas se aprueban por cuenta, no por número**: las tres que se
 * aprobaron el 2026-08-19 sirven para la WABA de prueba y para ninguna otra.
 *
 * ## Por qué el criterio 1 tiene su escenario montado al revés
 *
 * Hoy `META_WABA_ID` es una variable de entorno global. Es **exactamente la misma
 * forma** del bug que se arregló el 2026-08-20 en `MetaAdapter`, donde el emisor
 * salía de `config('services.meta.phone_number_id')` y el cliente de una PyME
 * recibía el mensaje desde el número de otra. Con un solo piloto no se nota.
 *
 * Por eso, igual que en `EmisorPorTenantTest`, **el valor global de `config`
 * apunta al tenant A** y todo lo que se ejercita es del tenant B: si el código
 * lee la config, las plantillas del cliente nuevo se crean en la cuenta ajena.
 *
 * ## ⚠️ Decisiones que tuve que tomar para poder escribir el test
 *
 * El ticket fija los criterios, no la superficie. Estas son mías y son
 * negociables — lo que **no** es negociable es el comportamiento que afirman:
 *
 * | Elección | Por qué | Alternativa razonable |
 * | :-- | :-- | :-- |
 * | `App\Meta\AltaDeCuenta::crearPlantillas(Integration $meta)` | El ticket dice "paso de alta" y no dice qué lo dispara: ni comando, ni pantalla, ni evento. Pruebo el servicio; el disparador lo envuelve | Un comando `cuenta:dar-de-alta {tenant}` |
 * | `App\Meta\PlantillasDelTenant::estados()` / `::todasAprobadas()` | El criterio 3 pide "se puede consultar" y no dice dónde se guarda | Una tabla propia de registros de plantilla |
 * | `integrations.settings['waba_id']` | Lo fija el propio ticket: *"va en `integrations.settings`, donde ya vive el `verify_token`"* | — |
 * | `App\Meta\SinCuentaDeWhatsApp` | El criterio 5 pide "error explícito" y no lo nombra | Cualquier excepción propia que diga qué falta |
 *
 * **Las aserciones sobre el payload son a propósito agnósticas de la forma.** No
 * afirmo el nombre de las claves de `POST /{waba-id}/message_templates`: busco el
 * cuerpo por su contenido y los botones por su `type`. Lo que el criterio manda
 * es *qué* se crea y *en qué cuenta*, no cómo se serializa.
 */
class AltaDeCuentaDeWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    /** La WABA del tenant A. Es también la que queda en `config`, a propósito. */
    private const WABA_A = '1360872979217448';

    /** La WABA del tenant B. No es substring de la de A: la aserción distingue. */
    private const WABA_B = '9925471068433129';

    private const PHONE_A = '1053554814514902';

    private const PHONE_B = '7742019983365574';

    private const T_RECORDATORIO = 'recordatorio_turno_24h';

    private const T_CONFIRMACION = 'confirmacion_reserva';

    private const T_AVISO = 'aviso_turno_2h';

    /**
     * Los cuerpos que Meta aprobó el 2026-08-19, textuales.
     *
     * Son contractuales: los cuatro `{{n}}` son posicionales y uno de más, uno de
     * menos o en otro orden hace que Meta **rechace el mensaje entero**, y ese
     * error no se ve hasta producción. Salen de
     * `.claude/docs/plan-for-diego/plantillas-meta.md`.
     */
    private const CUERPOS = [
        self::T_RECORDATORIO => "Hola {{1}}, te recordamos tu turno en {{2}}.\n\nServicio: {{3}}\nCuándo: {{4}}\n\n¿Nos confirmás que venís?",
        self::T_CONFIRMACION => "Hola {{1}}, tu turno en {{2}} quedó reservado.\n\nServicio: {{3}}\nCuándo: {{4}}\n\nTe vamos a escribir un día antes para recordártelo.",
        self::T_AVISO => "Hola {{1}}, te esperamos hoy en {{2}}.\n\nServicio: {{3}}\nHorario: {{4}}\n\n¡Nos vemos!",
    ];

    /** El orden es contractual: la aprobación congeló el texto y que son tres. */
    private const BOTONES = ['Confirmar', 'Re-agendar', 'Cancelar'];

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Integration $metaA;

    private Integration $metaB;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * El global apunta a la cuenta del tenant A. Es el corazón del criterio 1:
         * cualquier plantilla de B que se cree en esta WABA está leyendo la config
         * y no la integración del tenant.
         */
        config()->set('services.meta.waba_id', self::WABA_A);

        [$this->tenantA, $this->metaA] = $this->pyme('Peluquería Sur', 'peluqueria', self::PHONE_A, self::WABA_A);
        [$this->tenantB, $this->metaB] = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_B, self::WABA_B);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ montaje

    /** @return array{0:Tenant,1:Integration} */
    private function pyme(string $nombre, string $slug, string $phoneNumberId, ?string $wabaId): array
    {
        $tenant = Tenant::create([
            'name' => $nombre, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $settings = ['verify_token' => 'tok-'.$slug];

        // El `waba_id` convive con el `verify_token` en el mismo JSON: lo fija el
        // alcance del ticket. `null` es la PyME a la que todavía no se lo cargaron.
        if ($wabaId !== null) {
            $settings['waba_id'] = $wabaId;
        }

        $meta = Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId, 'access_token' => 'token-'.$slug,
            'settings' => $settings, 'status' => 'connected',
        ]);

        return [$tenant, $meta];
    }

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` acumula stubs: un segundo llamado no reemplaza al primero
     * y el doble nuevo nunca se usa. Ya hizo pasar tres tests de fallo en T-026
     * sin que probaran nada. Por eso el estado que devuelve Meta se decide acá,
     * por WABA, y no re-fakeando a mitad de camino.
     *
     * @param  array<string,string>  $estadoPorWaba  Qué contesta Meta al crear, por cuenta.
     */
    private function fakes(array $estadoPorWaba = []): void
    {
        Http::fake(['graph.facebook.com/*' => function ($request) use ($estadoPorWaba) {
            $estado = 'PENDING';

            foreach ($estadoPorWaba as $waba => $valor) {
                if (str_contains($request->url(), '/'.$waba.'/')) {
                    $estado = $valor;
                }
            }

            return Http::response([
                'id' => (string) random_int(1_000_000, 9_999_999),
                'status' => $estado,
                'category' => 'UTILITY',
            ]);
        }]);
    }

    private function darDeAlta(Tenant $tenant, Integration $meta): void
    {
        TenantContext::runAs((string) $tenant->id, fn () => app(AltaDeCuenta::class)->crearPlantillas($meta));
    }

    // --------------------------------------------------------------- utilidades

    /** @return array<int,string> Las URLs que efectivamente se le pidieron a Meta. */
    private function urlsAMeta(): array
    {
        $urls = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com')) {
                $urls[] = $req->url();
            }
        }

        return $urls;
    }

    /**
     * Las plantillas que se pidieron crear, por nombre.
     *
     * @return array<string,array{url:string,payload:array<string,mixed>}>
     */
    private function plantillasCreadas(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (! str_contains($req->url(), 'message_templates')) {
                continue;
            }

            $payload = (array) $req->data();
            $nombre = $this->nombreDe($payload);

            if ($nombre !== null) {
                $out[$nombre] = ['url' => $req->url(), 'payload' => $payload];
            }
        }

        return $out;
    }

    /**
     * Las cuentas en las que se creó alguna plantilla.
     *
     * ⚠️ El `strval` no es adorno: PHP convierte a `int` toda clave de array que
     * parezca un entero, así que `array_keys()` sobre un acumulador indexado por
     * WABA devuelve `int(9925471068433129)` y un `assertSame` contra el string
     * **no lo puede hacer pasar ninguna implementación**. La comparación tiene
     * que quedar en `assertSame`; lo que se arregla es el tipo, acá.
     *
     * @return array<int,string>
     */
    private function wabasUsadas(): array
    {
        $wabas = [];

        foreach ($this->plantillasCreadas() as $plantilla) {
            foreach ([self::WABA_A, self::WABA_B] as $waba) {
                // El segmento entero de la ruta y no un `str_contains` suelto: un
                // número que aparezca en el query string o dentro de otro no cuenta.
                if (str_contains($plantilla['url'], '/'.$waba.'/message_templates')) {
                    $wabas[$waba] = true;
                }
            }
        }

        return array_map('strval', array_keys($wabas));
    }

    /** @param  array<string,mixed>  $payload */
    private function nombreDe(array $payload): ?string
    {
        $conocidos = [self::T_RECORDATORIO, self::T_CONFIRMACION, self::T_AVISO];

        foreach ($this->valores($payload) as $valor) {
            if (is_string($valor) && in_array($valor, $conocidos, true)) {
                return $valor;
            }
        }

        return null;
    }

    /**
     * El cuerpo de la plantilla, sea cual sea la clave bajo la que viaje.
     *
     * @param  array<string,mixed>  $payload
     */
    private function cuerpoDe(array $payload): ?string
    {
        foreach ($this->valores($payload) as $valor) {
            if (is_string($valor) && str_contains($valor, '{{1}}') && str_contains($valor, 'Hola')) {
                return $valor;
            }
        }

        return null;
    }

    /**
     * Los textos de los botones de respuesta rápida, en el orden en que viajan.
     *
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private function quickRepliesDe(array $payload): array
    {
        $textos = [];

        $recorrer = function ($nodo) use (&$recorrer, &$textos) {
            if (! is_array($nodo)) {
                return;
            }

            $tipo = $nodo['type'] ?? null;

            if (is_string($tipo) && strtoupper($tipo) === 'QUICK_REPLY' && isset($nodo['text'])) {
                $textos[] = (string) $nodo['text'];
            }

            foreach ($nodo as $hijo) {
                $recorrer($hijo);
            }
        };

        $recorrer($payload);

        return $textos;
    }

    /** @param  array<string,mixed>  $payload */
    private function contieneValor(array $payload, string $buscado): bool
    {
        foreach ($this->valores($payload) as $valor) {
            if (is_string($valor) && strcasecmp($valor, $buscado) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $nodo
     * @return array<int,mixed>
     */
    private function valores(array $nodo): array
    {
        $out = [];

        foreach ($nodo as $valor) {
            if (is_array($valor)) {
                $out = array_merge($out, $this->valores($valor));

                continue;
            }

            $out[] = $valor;
        }

        return $out;
    }

    /**
     * @param  array<int,string>  $valores
     * @return array<int,string>
     */
    private function ordenado(array $valores): array
    {
        sort($valores);

        return $valores;
    }

    // ------------------------------ criterio 1 · la cuenta sale del tenant

    /**
     * El criterio en su forma más directa: las plantillas del cliente nuevo se
     * crean en **su** cuenta, aunque la variable global apunte a otra.
     */
    public function test_el_alta_crea_las_plantillas_en_la_waba_del_tenant_y_no_en_la_global(): void
    {
        $this->fakes();

        $this->darDeAlta($this->tenantB, $this->metaB);

        $this->assertNotEmpty($this->urlsAMeta(),
            'Precondición: el alta tiene que haberle pedido algo a Meta.');

        $this->assertSame([self::WABA_B], $this->wabasUsadas(),
            'Las plantillas del tenant B no se crearon únicamente en la WABA del tenant B. '
            .'Si aparece la del tenant A, el `waba_id` se está leyendo de '
            ."config('services.meta.waba_id') y no de la integración. URLs: "
            .implode(', ', $this->urlsAMeta()));
    }

    /**
     * Dos PyMEs, dos altas, dos cuentas distintas.
     *
     * Es la afirmación literal del criterio —*cada* cliente en *su* cuenta— y la
     * que no puede cumplirse con una WABA global: las seis peticiones saldrían a
     * la misma URL.
     */
    public function test_dos_tenants_reciben_sus_plantillas_cada_uno_en_su_propia_cuenta(): void
    {
        $this->fakes();

        $this->darDeAlta($this->tenantA, $this->metaA);
        $urlsDeA = $this->urlsAMeta();

        $this->darDeAlta($this->tenantB, $this->metaB);
        $urlsDeB = array_slice($this->urlsAMeta(), count($urlsDeA));

        $this->assertNotEmpty($urlsDeA, 'Precondición: el alta del tenant A tiene que haber pedido algo.');
        $this->assertNotEmpty($urlsDeB, 'Precondición: el alta del tenant B tiene que haber pedido algo.');

        foreach ($urlsDeA as $url) {
            $this->assertStringContainsString('/'.self::WABA_A.'/message_templates', $url,
                'Una plantilla del tenant A no se creó en la cuenta del tenant A: '.$url);
        }

        foreach ($urlsDeB as $url) {
            $this->assertStringContainsString('/'.self::WABA_B.'/message_templates', $url,
                'Una plantilla del tenant B no se creó en la cuenta del tenant B: '.$url);
        }
    }

    // --------------------------- criterio 2 · las tres plantillas, exactas

    public function test_el_alta_crea_las_tres_plantillas_aprobadas(): void
    {
        $this->fakes();

        $this->darDeAlta($this->tenantB, $this->metaB);

        $this->assertSame(
            $this->ordenado([self::T_AVISO, self::T_CONFIRMACION, self::T_RECORDATORIO]),
            $this->ordenado(array_keys($this->plantillasCreadas())),
            'El alta no creó las tres plantillas del cliente nuevo. Sin las tres, ni T-037 ni el '
            .'acuse de reserva tienen con qué salir en esa cuenta.'
        );
    }

    /**
     * El idioma también lo congela la aprobación: con otro código, el envío falla
     * con `template not found` y el error no dice cuál de los dos está mal.
     *
     * ⚠️ La categoría `UTILITY` no está en los criterios del ticket: sale de
     * `plantillas-meta.md`. Va acá porque una plantilla recategorizada a Marketing
     * cuesta más y exige otros requisitos.
     */
    public function test_las_tres_plantillas_se_crean_en_es_ar_y_como_utility(): void
    {
        $this->fakes();

        $this->darDeAlta($this->tenantB, $this->metaB);

        $creadas = $this->plantillasCreadas();
        $this->assertCount(3, $creadas, 'Precondición: tienen que haberse pedido las tres plantillas.');

        foreach ($creadas as $nombre => $plantilla) {
            $this->assertTrue($this->contieneValor($plantilla['payload'], 'es_AR'),
                "La plantilla {$nombre} no se creó con el idioma es_AR.");

            $this->assertTrue($this->contieneValor($plantilla['payload'], 'UTILITY'),
                "La plantilla {$nombre} no se creó en la categoría UTILITY.");
        }
    }

    public function test_los_cuerpos_de_las_tres_plantillas_son_los_aprobados(): void
    {
        $this->fakes();

        $this->darDeAlta($this->tenantB, $this->metaB);

        $creadas = $this->plantillasCreadas();
        $this->assertCount(3, $creadas, 'Precondición: tienen que haberse pedido las tres plantillas.');

        foreach (self::CUERPOS as $nombre => $esperado) {
            $this->assertArrayHasKey($nombre, $creadas, "No se pidió crear la plantilla {$nombre}.");

            $this->assertSame($esperado, $this->cuerpoDe($creadas[$nombre]['payload']),
                "El cuerpo de {$nombre} no es el que Meta aprobó el 2026-08-19. Un cuerpo distinto "
                .'es otra plantilla: cambiarla después obliga a volver a la cola de aprobación desde cero.');
        }
    }

    /**
     * Los cuatro parámetros son posicionales y en este orden: nombre del cliente,
     * negocio, servicio, fecha y hora. Uno de más, uno de menos o en otro orden y
     * Meta rechaza el mensaje entero **en producción**, no al crear la plantilla.
     */
    public function test_los_cuatro_parametros_son_posicionales_y_van_en_orden(): void
    {
        $this->fakes();

        $this->darDeAlta($this->tenantB, $this->metaB);

        $creadas = $this->plantillasCreadas();
        $this->assertCount(3, $creadas, 'Precondición: tienen que haberse pedido las tres plantillas.');

        foreach ($creadas as $nombre => $plantilla) {
            preg_match_all('/\{\{(\d+)\}\}/', (string) $this->cuerpoDe($plantilla['payload']), $encontrados);

            $this->assertSame(['1', '2', '3', '4'], $encontrados[1],
                "Los parámetros de {$nombre} no son los cuatro posicionales en orden.");
        }
    }

    public function test_el_recordatorio_lleva_los_tres_botones_quick_reply_en_orden(): void
    {
        $this->fakes();

        $this->darDeAlta($this->tenantB, $this->metaB);

        $creadas = $this->plantillasCreadas();
        $this->assertArrayHasKey(self::T_RECORDATORIO, $creadas,
            'Precondición: tiene que haberse pedido crear el recordatorio.');

        $this->assertSame(self::BOTONES, $this->quickRepliesDe($creadas[self::T_RECORDATORIO]['payload']),
            'El recordatorio no se creó con los tres botones aprobados en su orden. El orden es '
            .'contractual: `PlantillaRecordatorio24h::BOTONES` mapea la acción por índice, así que un '
            .'orden distinto en la cuenta del cliente hace que Confirmar cancele turnos, sin ningún '
            .'error que lo avise.');
    }

    /** Las otras dos son informativas: un botón de más es otra plantilla. */
    public function test_las_otras_dos_plantillas_se_crean_sin_botones(): void
    {
        $this->fakes();

        $this->darDeAlta($this->tenantB, $this->metaB);

        $creadas = $this->plantillasCreadas();

        foreach ([self::T_CONFIRMACION, self::T_AVISO] as $nombre) {
            $this->assertArrayHasKey($nombre, $creadas, "No se pidió crear la plantilla {$nombre}.");

            $this->assertSame([], $this->quickRepliesDe($creadas[$nombre]['payload']),
                "La plantilla {$nombre} se creó con botones y la aprobada no los tiene.");
        }
    }

    // ------------------- criterio 3 · el estado de aprobación, por tenant

    public function test_el_estado_de_aprobacion_de_cada_plantilla_queda_registrado_y_se_puede_consultar(): void
    {
        $this->fakes([self::WABA_B => 'PENDING']);

        $this->darDeAlta($this->tenantB, $this->metaB);

        $estados = PlantillasDelTenant::estados($this->metaB);

        $this->assertSame(
            $this->ordenado([self::T_AVISO, self::T_CONFIRMACION, self::T_RECORDATORIO]),
            $this->ordenado(array_keys($estados)),
            'El alta no dejó registrado el estado de las tres plantillas del tenant.'
        );

        foreach ($estados as $nombre => $estado) {
            $this->assertSame('PENDING', strtoupper((string) $estado),
                "El estado registrado de {$nombre} no es el que devolvió Meta al crearla.");
        }

        $this->assertFalse(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Con las tres plantillas pendientes, el tenant no puede darse por listo para mandar.');
    }

    public function test_una_plantilla_aprobada_por_meta_queda_registrada_como_aprobada(): void
    {
        $this->fakes([self::WABA_B => 'APPROVED']);

        $this->darDeAlta($this->tenantB, $this->metaB);

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Meta aprobó las tres plantillas del tenant y el registro sigue diciendo que no está listo. '
            .'Sin esto, el criterio 4 frena para siempre los envíos de un cliente que ya puede recibirlos.');
    }

    /**
     * El estado de un tenant no es el de otro.
     *
     * ⚠️ `tenant_id` es UUID: la comparación va **como string**. Un `(int)` sobre
     * un UUID devuelve `1` para todos, y una precondición escrita así pasaría con
     * la fuga presente.
     */
    public function test_el_estado_de_las_plantillas_de_un_tenant_no_es_el_de_otro(): void
    {
        $this->fakes([self::WABA_A => 'APPROVED', self::WABA_B => 'PENDING']);

        $this->darDeAlta($this->tenantA, $this->metaA);
        $this->darDeAlta($this->tenantB, $this->metaB);

        $this->assertNotSame((string) $this->tenantA->id, (string) $this->tenantB->id,
            'Precondición: los dos tenants tienen que ser distintos.');

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($this->metaA),
            'Las plantillas del tenant A están aprobadas y el registro dice que no.');

        $this->assertFalse(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Las plantillas del tenant B están pendientes y el registro las da por aprobadas: el '
            .'estado se está guardando o leyendo sin distinguir de qué PyME es.');
    }

    // --------------------- criterio 5 · sin cuenta cargada, error explícito

    /**
     * Un tenant sin `waba_id` no puede darse de alta, y el error dice qué falta.
     *
     * Lo que el criterio evita es el otro camino: pegarle a Meta con una URL
     * incompleta y recibir un error de la plataforma que no menciona ni al tenant
     * ni al dato que falta. Por eso se afirma también que **no salió ninguna
     * petición**.
     */
    public function test_un_tenant_sin_waba_id_falla_con_un_error_que_dice_que_falta_la_cuenta(): void
    {
        $this->fakes();

        [$tenantSinCuenta, $metaSinCuenta] = $this->pyme('Estudio Centro', 'estudio', '4410293847561122', null);

        $fallo = null;

        try {
            $this->darDeAlta($tenantSinCuenta, $metaSinCuenta);
        } catch (\Throwable $e) {
            $fallo = $e;
        }

        $this->assertNotNull($fallo,
            'El alta de un tenant sin `waba_id` no falló: siguió como si la cuenta estuviera cargada.');

        $this->assertSame([], $this->urlsAMeta(),
            'Se le pegó a Meta sin tener el `waba_id` del tenant: lo que vuelve es un error de la '
            .'plataforma que no dice qué falta. URLs: '.implode(', ', $this->urlsAMeta()));

        $this->assertInstanceOf(SinCuentaDeWhatsApp::class, $fallo,
            'El alta falló con una excepción cualquiera en vez de una que diga que falta la cuenta: '
            .$fallo::class.' · '.$fallo->getMessage());

        $this->assertMatchesRegularExpression('/waba/i', $fallo->getMessage(),
            'El error no menciona el `waba_id`, que es el dato que falta cargar.');
    }
}
