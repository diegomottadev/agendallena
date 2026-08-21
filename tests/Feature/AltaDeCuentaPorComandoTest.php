<?php

namespace Tests\Feature;

use App\Meta\AltaDeCuenta;
use App\Meta\PlantillasDelTenant;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-050 · **Se puede dar de alta la cuenta de WhatsApp de una PyME con un comando.**
 *
 * `AltaDeCuenta` está implementada y probada, pero en producción **no la llama
 * nadie**: sus únicos invocadores son cuatro tests. El alcance del ticket dice
 * *"paso de alta que crea las tres plantillas por API"*, y una clase que nadie
 * puede invocar no es un paso de alta.
 *
 * ## Por qué el hueco no es cosmético
 *
 * El `waba_id` de una PyME nueva solo se puede cargar a mano, editando un JSON
 * encriptado. Hasta que alguien lo haga, `EnviarRecordatorios::tieneCuentaDeWhatsApp()`
 * le frena **todos** los recordatorios a ese cliente: el freno que existe para no
 * mandar a ciegas se convierte en un bloqueo permanente para todo cliente nuevo,
 * y el síntoma en producción es «a este cliente no le llega nada», sin error.
 *
 * ## ⚠️ Decisiones que tuve que tomar para poder escribir el test
 *
 * El criterio fija el comportamiento, no la superficie. Estas son mías y son
 * negociables — lo que **no** es negociable es lo que los tests afirman:
 *
 * | Elección | Por qué | Alternativa razonable |
 * | :-- | :-- | :-- |
 * | `cuenta:dar-de-alta {tenant}` | Lo anticipa la cabecera de `AltaDeCuentaDeWhatsAppTest`, y es la convención del proyecto: `conversaciones:expirar`, `recordatorios:enviar`, `agendamientos:conciliar` | Cualquier otro verbo, mientras el argumento nombre al tenant |
 * | El argumento es el **UUID** del tenant | Es el identificador canónico y es lo que `TenantContext::runAs()` necesita | ⚠️ Que acepte también el `slug` sería más cómodo para el operador. **No lo afirmo**: el criterio no lo pide |
 * | `--waba-id=` para cargar la cuenta | El criterio dice «queda el `waba_id` guardado», o sea que el dato **entra por el comando**: si ya tuviera que estar cargado a mano, el hueco seguiría abierto | Un segundo argumento posicional |
 * | Sin `--waba-id`, usa el que ya esté cargado | Es el caso del cliente al que hay que recrearle las plantillas | Exigir siempre la opción |
 *
 * **No hay ninguna aserción sobre el `access_token`, ni parcial.** Un test que
 * afirme sobre un token lo imprime en el diff cuando falla. Que el comando
 * tampoco lo imprima queda como requisito escrito, no como aserción.
 */
class AltaDeCuentaPorComandoTest extends TestCase
{
    use RefreshDatabase;

    private const COMANDO = 'cuenta:dar-de-alta';

    /** La WABA del tenant A, el cliente que ya está andando. */
    private const WABA_A = '1360872979217448';

    /** La WABA del tenant B, el cliente nuevo. No es substring de la de A. */
    private const WABA_B = '9925471068433129';

    private const T_RECORDATORIO = 'recordatorio_turno_24h';

    private const T_CONFIRMACION = 'confirmacion_reserva';

    private const T_AVISO = 'aviso_turno_2h';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Integration $metaA;

    private Integration $metaB;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * El global apunta a la cuenta del tenant A, igual que en
         * `AltaDeCuentaDeWhatsAppTest` y `EmisorPorTenantTest`: si el comando lee
         * la config en vez de lo que se le pidió, las plantillas del cliente
         * nuevo se crean en la cuenta del cliente viejo y con un solo piloto no
         * se nota.
         */
        config()->set('services.meta.waba_id', self::WABA_A);

        // A ya tiene su cuenta cargada. B es la PyME nueva: **todavía no la tiene**,
        // que es exactamente el estado en el que el alta tiene que servir.
        [$this->tenantA, $this->metaA] = $this->pyme('Peluquería Sur', 'peluqueria', '1053554814514902', self::WABA_A);
        [$this->tenantB, $this->metaB] = $this->pyme('Consultorio Norte', 'consultorio', '7742019983365574', null);
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
     * sin que probaran nada. Por eso qué contesta Meta se decide acá adentro, por
     * plantilla, y nunca re-fakeando a mitad de test.
     *
     * @param  array<int,string>  $rechaza  Nombres de plantilla que Meta rechaza.
     */
    private function fakes(array $rechaza = []): void
    {
        Http::fake(['graph.facebook.com/*' => function ($request) use ($rechaza) {
            $nombre = $this->nombreDe((array) $request->data());

            if ($nombre !== null && in_array($nombre, $rechaza, true)) {
                return Http::response([
                    'error' => ['code' => 100, 'message' => 'Invalid parameter', 'type' => 'OAuthException'],
                ], 400);
            }

            return Http::response([
                'id' => (string) random_int(1_000_000, 9_999_999),
                'status' => 'PENDING',
                'category' => 'UTILITY',
            ]);
        }]);
    }

    // --------------------------------------------------------------- utilidades

    /**
     * Las plantillas que se pidieron crear, por nombre.
     *
     * Agnóstico de la forma del payload a propósito: lo que el criterio manda es
     * *qué* se crea y *en qué cuenta*, no cómo se serializa.
     *
     * @return array<string,array{url:string,status:int}>
     */
    private function plantillasPedidas(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (! str_contains($req->url(), 'graph.facebook.com')) {
                continue;
            }

            $nombre = $this->nombreDe((array) $req->data());

            if ($nombre !== null) {
                $out[$nombre] = ['url' => $req->url(), 'status' => $res->status()];
            }
        }

        return $out;
    }

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
     * Las cuentas en las que se pidió crear alguna plantilla.
     *
     * ⚠️ El `strval` no es adorno: PHP convierte a `int` toda clave de array que
     * parezca un entero, así que sin él `assertSame` contra el string no lo puede
     * hacer pasar ninguna implementación.
     *
     * @return array<int,string>
     */
    private function wabasUsadas(): array
    {
        $wabas = [];

        foreach ($this->urlsAMeta() as $url) {
            foreach ([self::WABA_A, self::WABA_B] as $waba) {
                // El segmento entero de la ruta y no un `str_contains` suelto: un
                // número dentro de otro o en el query string no cuenta.
                if (str_contains($url, '/'.$waba.'/message_templates')) {
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

    /** El `waba_id` que hoy tiene guardado la integración, releído de la base. */
    private function wabaGuardadoDe(Integration $meta): ?string
    {
        $fresco = Integration::query()->find($meta->getKey());

        if ($fresco === null) {
            return null;
        }

        $settings = (array) ($fresco->settings ?? []);
        $waba = trim((string) ($settings['waba_id'] ?? ''));

        return $waba !== '' ? $waba : null;
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

    // ---------------------------------- 1 · el comando existe y da de alta

    /**
     * El criterio en su forma más directa: un operador corre un comando y la PyME
     * nueva queda con su cuenta cargada y sus tres plantillas pedidas **en esa
     * cuenta**.
     */
    public function test_el_comando_carga_el_waba_id_del_tenant_y_le_crea_las_tres_plantillas(): void
    {
        $this->fakes();

        $this->assertNull($this->wabaGuardadoDe($this->metaB),
            'Precondición: el tenant nuevo tiene que arrancar sin `waba_id` cargado.');

        $this->artisan(self::COMANDO, [
            'tenant' => (string) $this->tenantB->id,
            '--waba-id' => self::WABA_B,
        ])->assertSuccessful();

        $this->assertSame(self::WABA_B, $this->wabaGuardadoDe($this->metaB),
            'El comando no dejó guardado el `waba_id` de la PyME nueva. Mientras no esté, '
            .'`EnviarRecordatorios::tieneCuentaDeWhatsApp()` le frena todos los recordatorios a ese '
            .'cliente y no hay ningún error que lo avise.');

        $this->assertSame(
            $this->ordenado([self::T_AVISO, self::T_CONFIRMACION, self::T_RECORDATORIO]),
            $this->ordenado(array_keys($this->plantillasPedidas())),
            'El comando no le pidió a Meta las tres plantillas del cliente nuevo.'
        );

        $this->assertSame([self::WABA_B], $this->wabasUsadas(),
            'Las plantillas no se crearon únicamente en la cuenta del tenant nombrado. Si aparece la '
            ."del tenant A, el `waba_id` se está leyendo de config('services.meta.waba_id') y no de la "
            .'integración. URLs: '.implode(', ', $this->urlsAMeta()));
    }

    /** Lo que quedó registrado es lo que Meta contestó, y se puede consultar. */
    public function test_el_comando_deja_registrado_el_estado_de_las_tres_plantillas(): void
    {
        $this->fakes();

        $this->artisan(self::COMANDO, [
            'tenant' => (string) $this->tenantB->id,
            '--waba-id' => self::WABA_B,
        ])->assertSuccessful();

        $this->assertSame(
            $this->ordenado([self::T_AVISO, self::T_CONFIRMACION, self::T_RECORDATORIO]),
            $this->ordenado(array_keys(PlantillasDelTenant::estados($this->metaB))),
            'El alta por comando no dejó registrado el estado de las tres plantillas del tenant. Sin '
            .'ese registro, T-037 no sabe si el cliente ya puede recibir mensajes.'
        );
    }

    // ------------------------------ 2 · da de alta a quien se le nombra y a nadie más

    /**
     * Dos PyMEs montadas, una sola nombrada. La otra queda **intacta**.
     *
     * ⚠️ `tenant_id` es UUID: la comparación va como string. Un `(int)` sobre un
     * UUID devuelve `1` para todos y la precondición pasaría con la fuga presente.
     */
    public function test_el_comando_da_de_alta_al_tenant_que_se_le_pide_y_no_a_otro(): void
    {
        $this->fakes();

        $this->assertNotSame((string) $this->tenantA->id, (string) $this->tenantB->id,
            'Precondición: los dos tenants tienen que ser distintos.');

        $estadosPreviosDeA = PlantillasDelTenant::estados($this->metaA);

        $this->artisan(self::COMANDO, [
            'tenant' => (string) $this->tenantB->id,
            '--waba-id' => self::WABA_B,
        ])->assertSuccessful();

        $this->assertNotEmpty($this->urlsAMeta(),
            'Precondición: el alta tiene que haberle pedido algo a Meta.');

        foreach ($this->urlsAMeta() as $url) {
            $this->assertStringNotContainsString('/'.self::WABA_A.'/', $url,
                'Se creó una plantilla en la cuenta del tenant que nadie nombró: '.$url);
        }

        $this->assertSame(self::WABA_A, $this->wabaGuardadoDe($this->metaA),
            'El alta del tenant B le cambió el `waba_id` al tenant A.');

        $this->assertSame($estadosPreviosDeA, PlantillasDelTenant::estados($this->metaA),
            'El alta del tenant B le tocó el registro de plantillas del tenant A.');
    }

    // ------------------------------------- 3 · un tenant que no existe

    /**
     * El operador se equivoca al tipear y el comando lo dice.
     *
     * Lo que este criterio evita es el otro camino: seguir de largo con un `null`
     * y reventar más adelante con un error de base o de propiedad sobre `null`,
     * que no menciona ni al comando ni al dato que estaba mal.
     */
    public function test_el_comando_falla_de_forma_legible_si_el_tenant_no_existe(): void
    {
        $this->fakes();

        $inexistente = 'pyme-que-no-existe';

        $this->artisan(self::COMANDO, [
            'tenant' => $inexistente,
            '--waba-id' => self::WABA_B,
        ])
            ->expectsOutputToContain($inexistente)
            ->assertFailed();

        $this->assertSame([], $this->urlsAMeta(),
            'Se le pegó a Meta por un tenant que no existe. URLs: '.implode(', ', $this->urlsAMeta()));
    }

    // ------------------------- 4 · Meta rechaza una plantilla · RNF-03

    /**
     * Un alta a medias —dos plantillas creadas y una rechazada— es peor que una
     * que falla entera: el cliente parece dado de alta y le faltan mensajes, y el
     * síntoma aparece recién cuando el recordatorio no sale.
     *
     * ⚠️ La guarda del `status` de la respuesta no es adorno. Si el doble que
     * simula el rechazo nunca se usa, el comando corre contra el camino feliz y
     * este test pasaría sin probar nada — es exactamente lo que pasó con tres
     * tests de T-026.
     */
    public function test_si_meta_rechaza_una_plantilla_el_comando_lo_dice_y_no_termina_en_silencio(): void
    {
        $this->fakes(rechaza: [self::T_CONFIRMACION]);

        $this->artisan(self::COMANDO, [
            'tenant' => (string) $this->tenantB->id,
            '--waba-id' => self::WABA_B,
        ])
            ->expectsOutputToContain(self::T_CONFIRMACION)
            ->assertFailed();

        $pedidas = $this->plantillasPedidas();

        $this->assertArrayHasKey(self::T_CONFIRMACION, $pedidas,
            'Precondición: el comando tiene que haber pedido crear la plantilla que Meta rechaza.');

        $this->assertSame(400, $pedidas[self::T_CONFIRMACION]['status'],
            'El doble que simula el rechazo de Meta no se consumió: el comando corrió contra el camino '
            .'feliz y este test no probó nada.');
    }

    /** Lo que Meta rechazó queda registrado como rechazado, no como pendiente. */
    public function test_la_plantilla_rechazada_queda_registrada_como_rechazada(): void
    {
        $this->fakes(rechaza: [self::T_CONFIRMACION]);

        $this->artisan(self::COMANDO, [
            'tenant' => (string) $this->tenantB->id,
            '--waba-id' => self::WABA_B,
        ])->assertFailed();

        $this->assertFalse(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Con una plantilla rechazada, el tenant no puede darse por listo para mandar.');

        $this->assertSame(AltaDeCuenta::RECHAZADA,
            strtoupper((string) (PlantillasDelTenant::estados($this->metaB)[self::T_CONFIRMACION] ?? '')),
            'La plantilla que Meta rechazó no quedó registrada como rechazada.');
    }

    // ---------------------------- 5 · correrlo dos veces · decisión abierta

    /**
     * ⚠️ **El ticket no dice qué tiene que pasar al correrlo dos veces**: si es
     * idempotente, si avisa que ya está dado de alta o si vuelve a pedir las tres
     * plantillas. **No lo invento**: este test no afirma nada sobre el código de
     * salida de la segunda corrida ni sobre si vuelve a llamar a Meta.
     *
     * Lo que sí afirma es lo único que no admite discusión bajo ninguna de las
     * tres lecturas: que el registro de plantillas **no queda duplicado** y que el
     * `waba_id` **no queda pisado con basura**. Un `waba_id` vacío tras la segunda
     * corrida deja al cliente sin recordatorios, que es el mismo daño que el hueco
     * venía a cerrar.
     */
    public function test_correr_el_comando_dos_veces_no_duplica_plantillas_ni_pisa_el_waba_id(): void
    {
        $this->fakes();

        $parametros = [
            'tenant' => (string) $this->tenantB->id,
            '--waba-id' => self::WABA_B,
        ];

        $this->artisan(self::COMANDO, $parametros)->assertSuccessful();

        $estadosTrasLaPrimera = PlantillasDelTenant::estados($this->metaB);

        // El código de salida de la segunda corrida es la decisión abierta: se
        // ejecuta y se ignora a propósito.
        $this->artisan(self::COMANDO, $parametros)->run();

        $this->assertSame(
            $this->ordenado(array_keys($estadosTrasLaPrimera)),
            $this->ordenado(array_keys(PlantillasDelTenant::estados($this->metaB))),
            'La segunda corrida cambió el conjunto de plantillas registradas del tenant: quedaron '
            .'duplicadas o desaparecidas.'
        );

        $this->assertSame(self::WABA_B, $this->wabaGuardadoDe($this->metaB),
            'La segunda corrida pisó el `waba_id` del tenant. Sin `waba_id`, '
            .'`tieneCuentaDeWhatsApp()` le frena todos los recordatorios a ese cliente.');
    }

    // ============================================================================
    // 6 · La PyME que queda muerta con las plantillas en `PENDING`
    // ============================================================================
    //
    // `PENDING` es el estado normal de **todo cliente recién dado de alta**: Meta
    // aprueba de forma asincrónica y su demora no la controlamos. Pero hoy una
    // plantilla registrada como `PENDING` no puede llegar nunca a `APPROVED`:
    //
    //   1. `PlantillasDelTenant::registrar()` solo se llama desde el alta.
    //   2. No hay handler del webhook `message_template_status_update` de Meta,
    //      así que nada le avisa al sistema que la aprobación salió.
    //   3. `DarDeAltaCuenta::plantillasPorCrear()` saltea las `PENDING`, informa
    //      «ya tiene sus tres plantillas creadas» y devuelve éxito.
    //   4. `EnviarRecordatorios` frena el envío mientras no estén las tres
    //      `APPROVED`.
    //
    // **Resultado: la PyME no manda un solo recordatorio, nunca, y el operador no
    // tiene ninguna forma de destrabarla.** Vuelve a correr el comando, le dice
    // que está todo bien, y sigue sin salir nada. Es el fallo silencioso que
    // RNF-03 prohíbe, y deja muerta la mitad del producto que se cobra: el
    // recordatorio t-24h es lo que sostiene el KPI de asistencia ≥ 92%.
    //
    // El comportamiento que se exige acá es el camino más barato que lo destraba:
    // **el comando re-consulta a Meta el estado actual y registra lo que Meta
    // conteste.** El alta pasa a ser también un «sincronizar estado».
    //
    // ## ⚠️ Decisiones de diseño de esta tanda
    //
    // | Elección | Por qué | Qué NO se afirma |
    // | :-- | :-- | :-- |
    // | La consulta es **cualquier petición de lectura** (no `POST`) a la cuenta del tenant | El criterio manda *que se consulte el estado en la cuenta del tenant*, no la forma del endpoint | No se afirma el path, ni el verbo exacto, ni los `fields` |
    // | El doble contesta con la **forma canónica de Meta** para `GET /{waba-id}/message_templates`: `{"data":[{"name":…,"status":…}]}` | Algo tiene que contestar el doble, y esa es la única lista de plantillas que expone la Graph API | ⚠️ Si el implementador elige otro endpoint, **este doble hay que cambiarlo**. Es la única atadura que no pude evitar |
    // | Con las tres todavía `PENDING`, el comando **no devuelve éxito** | Es el pedido textual: hoy informa «ya tiene sus tres plantillas creadas» y devuelve `SUCCESS`, que es justo lo que engaña al operador | No se afirma **qué** código de error: solo que no es 0 |
    // | «Lo dice» se verifica con `/PENDING|PENDIENTE/i` sobre la salida | El operador tiene que leer el estado real | No se afirma la redacción del mensaje |
    //
    // ⚠️ **Lo que el criterio no dice y por lo tanto ningún test afirma:** qué pasa
    // si Meta contesta con una plantilla que **no** está entre las tres nuestras,
    // si contesta `REJECTED` para una que teníamos `PENDING` (¿se re-crea en la
    // misma corrida?), y qué pasa si la consulta a Meta falla con un `5xx`.

    private const PENDIENTE = 'PENDING';

    private const APROBADA = 'APPROVED';

    // ------------------------------------------------------- montaje de esta tanda

    /**
     * Deja al tenant como queda **el día después del alta**: cuenta cargada y las
     * tres plantillas registradas en el estado que Meta contesta siempre.
     *
     * Se monta con `PlantillasDelTenant::registrar()`, que es el único camino que
     * el sistema tiene para que un estado exista, en vez de escribir el JSON a
     * mano.
     */
    private function conLasTresEn(Integration $meta, string $estado, ?string $waba = null): void
    {
        if ($waba !== null) {
            $fresco = Integration::query()->find($meta->getKey()) ?? $meta;
            $settings = (array) ($fresco->settings ?? []);
            $settings['waba_id'] = $waba;
            $meta->forceFill(['settings' => $settings])->save();
        }

        foreach ([self::T_RECORDATORIO, self::T_CONFIRMACION, self::T_AVISO] as $nombre) {
            PlantillasDelTenant::registrar($meta, $nombre, $estado);
        }
    }

    /**
     * **Un solo `Http::fake()` por test**, y qué contesta Meta se decide adentro
     * por cuenta.
     *
     * ⚠️ Un segundo `Http::fake()` no reemplaza al primero: acumula, el doble
     * nuevo nunca se usa y el test queda verde sin probar nada. Ya hizo pasar tres
     * tests de fallo en T-026.
     *
     * Lo que contesta depende de **la WABA de la URL**, no del orden de las
     * llamadas: así el aislamiento entre tenants se puede afirmar de verdad.
     *
     * @param  array<string,string>  $estadoPorWaba  Estado que Meta reporta para cada cuenta.
     */
    private function fakeDeConsulta(array $estadoPorWaba): void
    {
        Http::fake(['graph.facebook.com/*' => function ($request) use ($estadoPorWaba) {
            // Una creación de plantilla. No debería pasar en esta tanda, pero si
            // pasa el test lo tiene que poder ver, no explotar.
            if (strtoupper((string) $request->method()) === 'POST') {
                return Http::response([
                    'id' => (string) random_int(1_000_000, 9_999_999),
                    'status' => self::PENDIENTE,
                    'category' => 'UTILITY',
                ]);
            }

            $waba = $this->wabaDeLaUrl((string) $request->url());
            $estado = $estadoPorWaba[$waba] ?? self::PENDIENTE;

            $plantillas = [];

            foreach ([self::T_RECORDATORIO, self::T_CONFIRMACION, self::T_AVISO] as $nombre) {
                $plantillas[] = [
                    'id' => (string) crc32($waba.$nombre),
                    'name' => $nombre,
                    'status' => $estado,
                    'language' => 'es_AR',
                    'category' => 'UTILITY',
                ];
            }

            return Http::response(['data' => $plantillas, 'paging' => ['cursors' => ['before' => '', 'after' => '']]]);
        }]);
    }

    /** Qué cuenta nombra una URL de la Graph API, como string. */
    private function wabaDeLaUrl(string $url): string
    {
        foreach ([self::WABA_A, self::WABA_B] as $waba) {
            // Segmento entero de la ruta: un número dentro de otro no cuenta.
            if (str_contains($url, '/'.$waba.'/') || str_contains($url, '/'.$waba.'?')) {
                return (string) $waba;
            }
        }

        return '';
    }

    /**
     * Las URLs con las que se le **preguntó** algo a Meta: todo lo que no sea un
     * `POST` de creación.
     *
     * @return array<int,string>
     */
    private function consultasAMeta(): array
    {
        $urls = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (! str_contains($req->url(), 'graph.facebook.com')) {
                continue;
            }

            if (strtoupper((string) $req->method()) === 'POST') {
                continue;
            }

            $urls[] = $req->url();
        }

        return $urls;
    }

    /**
     * Las plantillas que se pidió **crear** (`POST`), por nombre.
     *
     * @return array<int,string>
     */
    private function creacionesPedidas(): array
    {
        $nombres = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (! str_contains($req->url(), 'graph.facebook.com')) {
                continue;
            }

            if (strtoupper((string) $req->method()) !== 'POST') {
                continue;
            }

            $nombres[] = $this->nombreDe((array) $req->data()) ?? $req->url();
        }

        return $nombres;
    }

    /**
     * Corre el comando y devuelve `[código de salida, salida completa]`.
     *
     * Va por `Artisan::call` y no por `$this->artisan()` porque estos tests
     * necesitan afirmar sobre lo que la salida **no** dice, y `PendingCommand` no
     * tiene una forma negativa de `expectsOutputToContain`.
     *
     * @return array{0:int,1:string}
     */
    private function correrSobre(Tenant $tenant): array
    {
        $codigo = Artisan::call(self::COMANDO, ['tenant' => (string) $tenant->id]);

        return [$codigo, Artisan::output()];
    }

    // ---------------------------------------------- 6.1 · se consulta a Meta

    /**
     * Una PyME cuyas plantillas Meta dejó `PENDING` está muerta: no manda un solo
     * recordatorio y el operador no tiene ninguna palanca. La única forma de que
     * el sistema se entere de que Meta ya aprobó es **ir a preguntar**, porque no
     * hay webhook de `message_template_status_update` que lo avise.
     *
     * Este test exige eso y nada más: que el comando no se quede con lo que tiene
     * guardado.
     */
    public function test_con_las_tres_plantillas_pendientes_el_comando_le_consulta_a_meta_el_estado_actual(): void
    {
        $this->fakeDeConsulta([self::WABA_B => self::PENDIENTE]);
        $this->conLasTresEn($this->metaB, self::PENDIENTE, self::WABA_B);

        $this->correrSobre($this->tenantB);

        $this->assertNotEmpty($this->consultasAMeta(),
            'El comando no le preguntó a Meta el estado actual de las plantillas: se quedó con el '
            .'`PENDING` que tenía guardado. Como no existe handler del webhook '
            .'`message_template_status_update`, esa consulta es el único camino por el que el sistema '
            .'puede enterarse de que Meta aprobó, y sin ella la PyME nunca manda un recordatorio.');
    }

    // ------------------------------------ 6.2 · el cliente queda destrabado

    /**
     * **El test que prueba que la PyME se destraba.**
     *
     * Meta ya aprobó las tres —lo normal a las pocas horas del alta— pero el
     * registro sigue diciendo `PENDING`, así que `EnviarRecordatorios` frena todo.
     * Después de correr el comando, `todasAprobadas()` tiene que dar `true`: es
     * literalmente el freno que decide si al cliente le sale el recordatorio
     * t-24h, el mensaje que sostiene el KPI de asistencia ≥ 92%.
     */
    public function test_si_meta_ya_aprobo_las_plantillas_el_tenant_queda_destrabado(): void
    {
        $this->fakeDeConsulta([self::WABA_B => self::APROBADA]);
        $this->conLasTresEn($this->metaB, self::PENDIENTE, self::WABA_B);

        $this->assertFalse(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Precondición: el tenant tiene que arrancar frenado, con las tres en `PENDING`.');

        $this->correrSobre($this->tenantB);

        $this->assertSame(
            [self::T_AVISO => self::APROBADA, self::T_CONFIRMACION => self::APROBADA, self::T_RECORDATORIO => self::APROBADA],
            $this->porNombre(PlantillasDelTenant::estados($this->metaB)),
            'El comando no registró lo que Meta contestó: las plantillas siguen figurando `PENDING` '
            .'aunque Meta ya las aprobó.'
        );

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($this->metaB),
            'La PyME sigue trabada después de correr el comando. `EnviarRecordatorios` le va a seguir '
            .'frenando **todos** los recordatorios, sin ningún error que lo avise, y el operador no '
            .'tiene otra palanca para destrabarla.');
    }

    // ------------------------------- 6.3 · Meta todavía no aprobó · RNF-03

    /**
     * Si Meta todavía no aprobó, el estado no se toca —no se puede inventar un
     * `APPROVED` que Meta no dio, porque el envío moriría en `template not
     * found`— pero el operador tiene que **ver** que siguen pendientes. Hoy lee
     * «ya tiene sus tres plantillas creadas», que es verdad y a la vez lo manda a
     * buscar el problema a cualquier otro lado.
     */
    public function test_si_meta_las_sigue_teniendo_pendientes_quedan_pendientes_y_el_comando_lo_dice(): void
    {
        $this->fakeDeConsulta([self::WABA_B => self::PENDIENTE]);
        $this->conLasTresEn($this->metaB, self::PENDIENTE, self::WABA_B);

        [, $salida] = $this->correrSobre($this->tenantB);

        $this->assertSame(
            [self::T_AVISO => self::PENDIENTE, self::T_CONFIRMACION => self::PENDIENTE, self::T_RECORDATORIO => self::PENDIENTE],
            $this->porNombre(PlantillasDelTenant::estados($this->metaB)),
            'El comando cambió el estado registrado de una plantilla que Meta sigue teniendo pendiente. '
            .'Un `APPROVED` inventado hace que el recordatorio muera en `template not found`.'
        );

        $this->assertMatchesRegularExpression('/PENDING|PENDIENTE/i', $salida,
            'El comando no le dice al operador que las plantillas siguen esperando a Meta. Salida: '.$salida);
    }

    /**
     * El código de salida es lo que mira un operador —y lo que mirará cualquier
     * automatización—: hoy devuelve éxito con el cliente muerto, y esa es la parte
     * del fallo que lo hace silencioso.
     *
     * ⚠️ No se afirma **cuál** es el código, solo que no es el de éxito.
     */
    public function test_si_meta_las_sigue_teniendo_pendientes_el_comando_no_informa_que_esta_todo_listo(): void
    {
        $this->fakeDeConsulta([self::WABA_B => self::PENDIENTE]);
        $this->conLasTresEn($this->metaB, self::PENDIENTE, self::WABA_B);

        [$codigo, $salida] = $this->correrSobre($this->tenantB);

        $this->assertStringNotContainsString('ya tiene sus tres plantillas creadas', $salida,
            'El comando informa que está todo hecho mientras la PyME no puede mandar un solo '
            .'recordatorio. Es el mensaje que hace que el operador deje de buscar.');

        $this->assertNotSame(0, $codigo,
            'El comando devolvió éxito con la PyME trabada. RNF-03: ningún camino termina en silencio.');
    }

    // --------------------------------- 6.4 · no se re-crea lo que ya existe

    /**
     * Una plantilla que ya existe en la cuenta de Meta **no se puede volver a
     * pedir**: contesta error de nombre duplicado y quedaría registrada como
     * `REJECTED`. O sea que una corrida inocente del comando —justo la que el
     * operador hace para destrabar al cliente— lo dejaría peor que antes.
     *
     * ⚠️ La precondición no es adorno: **sin ella este test pasa hoy sin probar
     * nada**, porque hoy el comando corta antes de hacer absolutamente nada y por
     * eso tampoco hace ningún `POST`. Exigir que la sincronización haya ocurrido
     * es lo que convierte "no creó nada" en una afirmación con contenido.
     */
    public function test_la_sincronizacion_no_vuelve_a_crear_una_plantilla_que_ya_existe(): void
    {
        $this->fakeDeConsulta([self::WABA_B => self::APROBADA]);
        $this->conLasTresEn($this->metaB, self::PENDIENTE, self::WABA_B);

        $this->correrSobre($this->tenantB);

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Precondición: la sincronización tiene que haber corrido, si no "no se creó nada" es cierto '
            .'por omisión y este test no prueba nada.');

        $this->assertSame([], $this->creacionesPedidas(),
            'Se pidió crear una plantilla que ya existe en la cuenta de Meta. Meta contesta error de '
            .'nombre duplicado y la plantilla queda registrada como rechazada: la corrida que venía a '
            .'destrabar al cliente lo rompe. Se pidieron: '.implode(', ', $this->creacionesPedidas()));
    }

    // ------------------------------------ 6.5 · aislamiento por tenant · RNF-01

    /**
     * RNF-01 · Sincronizar una PyME no toca el estado de otra.
     *
     * El daño concreto: si la sincronización de la PyME B pisara el registro de la
     * PyME A, un `PENDING` ajeno le frenaría a A todos los recordatorios de un día
     * para el otro, sin ningún error. Es la misma forma del bug del emisor que ya
     * apareció dos veces en este proyecto.
     *
     * ⚠️ Los estados se comparan como string y los tenants por UUID: un `(int)`
     * sobre un UUID devuelve `1` para todos y este test pasaría con la fuga puesta.
     */
    public function test_sincronizar_un_tenant_no_toca_el_estado_registrado_de_otro(): void
    {
        $this->fakeDeConsulta([self::WABA_B => self::APROBADA, self::WABA_A => self::PENDIENTE]);

        $this->conLasTresEn($this->metaA, self::APROBADA);
        $this->conLasTresEn($this->metaB, self::PENDIENTE, self::WABA_B);

        $this->assertNotSame((string) $this->tenantA->id, (string) $this->tenantB->id,
            'Precondición: los dos tenants tienen que ser distintos.');

        $estadosPreviosDeA = PlantillasDelTenant::estados($this->metaA);

        $this->correrSobre($this->tenantB);

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Precondición del aislamiento: la sincronización del tenant nombrado tiene que haber '
            .'hecho efecto, si no este test no prueba nada.');

        $this->assertSame($estadosPreviosDeA, PlantillasDelTenant::estados($this->metaA),
            'La sincronización del tenant B le cambió el estado registrado de las plantillas al '
            .'tenant A. Un estado ajeno le frena a A todos los recordatorios sin ningún error.');

        $this->assertSame(self::WABA_A, $this->wabaGuardadoDe($this->metaA),
            'La sincronización del tenant B le pisó la cuenta de WhatsApp al tenant A.');
    }

    /**
     * La cuenta que se consulta es **la del tenant**, no la de
     * `config('services.meta.waba_id')`.
     *
     * El global apunta a la cuenta del tenant A (ver `setUp`). Si el comando lo
     * lee, el estado que trae es el de las plantillas del cliente viejo: la PyME
     * nueva quedaría marcada `APPROVED` con sus plantillas sin aprobar, y el
     * recordatorio moriría en `template not found`. Con un solo piloto no se nota.
     */
    public function test_la_sincronizacion_consulta_la_cuenta_del_tenant_y_no_la_de_la_config(): void
    {
        $this->fakeDeConsulta([self::WABA_B => self::APROBADA, self::WABA_A => self::APROBADA]);

        $this->conLasTresEn($this->metaA, self::PENDIENTE);
        $this->conLasTresEn($this->metaB, self::PENDIENTE, self::WABA_B);

        $this->assertSame(self::WABA_A, config('services.meta.waba_id'),
            'Precondición: el global tiene que apuntar a la cuenta del otro tenant.');

        $this->correrSobre($this->tenantB);

        $consultas = $this->consultasAMeta();

        $this->assertNotEmpty($consultas,
            'El comando no consultó ninguna cuenta: no hay nada que aislar y la PyME sigue trabada.');

        foreach ($consultas as $url) {
            $this->assertSame(self::WABA_B, $this->wabaDeLaUrl($url),
                'Se consultó el estado de las plantillas en una cuenta que no es la del tenant '
                .'nombrado. Si es la del tenant A, el `waba_id` se está leyendo de '
                ."config('services.meta.waba_id') y no de la integración. URL: ".$url);
        }
    }

    /**
     * Los estados ordenados por nombre, para comparar sin depender del orden en
     * que se hayan registrado.
     *
     * @param  array<string,string>  $estados
     * @return array<string,string>
     */
    private function porNombre(array $estados): array
    {
        $estados = array_map(static fn ($e): string => strtoupper((string) $e), $estados);

        ksort($estados);

        return $estados;
    }
}
