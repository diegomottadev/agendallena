<?php

namespace Tests\Feature;

use App\Console\Commands\SincronizarPlantillas;
use App\Meta\PlantillasDelTenant;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * **La PyME trabada en `PENDING` se destraba sola.**
 *
 * `cuenta:dar-de-alta` puede re-consultarle a Meta el estado —eso lo fija
 * `AltaDeCuentaPorComandoTest`, sección 6—, pero eso le da al operador una
 * *palanca a ciegas*: **nadie le avisa cuándo Meta aprobó**, porque no existe
 * handler del webhook `message_template_status_update`. El operador tiene que
 * adivinar cuándo correr el comando.
 *
 * ## Por qué adivinar no alcanza, con números del propio código
 *
 * `EnviarRecordatorios` selecciona con
 * `whereBetween('start_time', [ahora+24h ± tolerancia])`
 * (`app/Console/Commands/EnviarRecordatorios.php:65` y `:83`). Que el freno de
 * plantillas **no consuma** el candado de `notification_logs` salva al turno que
 * sigue *dentro* de la ventana en la corrida siguiente; **al que ya la cruzó no
 * lo salva nada**. O sea que cada hora que la PyME queda trabada quema turnos que
 * no se recuperan, contra un KPI de asistencia ≥ 92%.
 *
 * Por eso la sincronización se agenda: la PyME se destraba sola.
 *
 * ## ⚠️ Decisiones de diseño que tuve que tomar
 *
 * | Elección | Por qué | Alternativa razonable |
 * | :-- | :-- | :-- |
 * | **Comando nuevo** `plantillas:sincronizar`, sin argumentos | Ver el bloque de abajo | Reusar `cuenta:dar-de-alta` con un `--todos` |
 * | La cadencia vive en `SincronizarPlantillas::MINUTOS_ENTRE_CORRIDAS` | Es el patrón que ya fijó `ConciliarAgendamientos::MINUTOS_ENTRE_CORRIDAS` (§ 7): mover la frecuencia tiene que ser **una línea** | Que viva en `config/services.php` |
 * | **El valor de la constante no se afirma** | Ningún documento fija la cadencia y no la invento. Diego la ratifica | — |
 * | La cadencia se verifica **corriendo el cron**, no comparando el string | «cada 15 minutos» y «a la hora en punto» se escriben con expresiones de forma distinta, y cuál corresponde depende del número que se ratifique. Comparar el texto ataría al implementador a una de las dos; medir el intervalo entre dos corridas reales, no | Fijar la expresión textual |
 *
 * ### Por qué un comando nuevo y no `cuenta:dar-de-alta --todos`
 *
 * Los dos tienen contratos incompatibles, y mezclarlos rompe uno de los dos:
 *
 * 1. **El código de salida.** `cuenta:dar-de-alta` *tiene que* fallar cuando
 *    quedan plantillas sin aprobar —es lo que `AltaDeCuentaPorComandoTest`
 *    sección 6 exige, para que el operador no lea «está todo listo»—. Una tarea
 *    agendada que devuelve `FAILURE` cada corrida porque una PyME sigue esperando
 *    a Meta es ruido puro, y el operador deja de mirarla.
 * 2. **`cuenta:dar-de-alta` crea** (`POST`) y esta tarea **solo lee**. Una tarea
 *    automática que puede crear plantillas en la cuenta de un cliente es un
 *    riesgo que nadie pidió correr.
 * 3. El argumento `tenant` es obligatorio y acá se recorren todas.
 *
 * La lógica de sincronizar un tenant es la misma y **debería compartirse**; lo que
 * no puede compartirse es la superficie. Nombre según la convención del repo
 * (`conversaciones:expirar`, `recordatorios:enviar`, `agendamientos:conciliar`).
 *
 * ⚠️ Fuera de este ciclo, por acuerdo: qué hacer si Meta devuelve una plantilla
 * que no es de las tres, si reporta `REJECTED` una que teníamos `PENDING`, y si
 * el estado sincronizado debería llevar marca de tiempo.
 */
class SincronizacionDePlantillasTest extends TestCase
{
    use RefreshDatabase;

    private const COMANDO = 'plantillas:sincronizar';

    /** La WABA del tenant A. A la que apunta el global, a propósito. */
    private const WABA_A = '1360872979217448';

    /** La WABA del tenant B. No es substring de la de A. */
    private const WABA_B = '9925471068433129';

    private const T_RECORDATORIO = 'recordatorio_turno_24h';

    private const T_CONFIRMACION = 'confirmacion_reserva';

    private const T_AVISO = 'aviso_turno_2h';

    private const PENDIENTE = 'PENDING';

    private const APROBADA = 'APPROVED';

    private const TZ = 'America/Bogota';

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Integration $metaA;

    private Integration $metaB;

    /** @var array<int,array{nivel:string,mensaje:string,contexto:array<string,mixed>}> */
    private array $registros = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * El global apunta a la cuenta del tenant A, igual que en
         * `EmisorPorTenantTest` y `AltaDeCuentaPorComandoTest`: si la tarea lee la
         * config en vez de la integración de cada PyME, consulta el estado de la
         * cuenta equivocada y con un solo piloto no se nota.
         */
        config()->set('services.meta.waba_id', self::WABA_A);

        /*
         * ⚠️ **El orden de creación importa** y es deliberado: A se crea primero,
         * así que queda primero bajo cualquier orden natural (`created_at`, o el
         * `id` UUIDv7, que es monótono). El test de resiliencia necesita que la
         * PyME que falla se procese **antes** que la otra; si fuera al revés, una
         * implementación que corta en el primer error pasaría igual.
         */
        [$this->tenantA, $this->metaA] = $this->pyme('Peluquería Sur', 'peluqueria', '1053554814514902', self::WABA_A);
        [$this->tenantB, $this->metaB] = $this->pyme('Consultorio Norte', 'consultorio', '7742019983365574', self::WABA_B);

        Log::listen(function ($m) {
            $this->registros[] = ['nivel' => $m->level, 'mensaje' => $m->message, 'contexto' => $m->context];
        });
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        $this->registros = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------ montaje

    /** @return array{0:Tenant,1:Integration} */
    private function pyme(string $nombre, string $slug, string $phoneNumberId, string $wabaId): array
    {
        $tenant = Tenant::create([
            'name' => $nombre, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $meta = Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId, 'access_token' => 'token-'.$slug,
            'settings' => ['verify_token' => 'tok-'.$slug, 'waba_id' => $wabaId],
            'status' => 'connected',
        ]);

        return [$tenant, $meta];
    }

    /**
     * Deja las tres plantillas del tenant en un estado, usando el único camino
     * que el sistema tiene para que un estado exista.
     */
    private function conLasTresEn(Integration $meta, string $estado): void
    {
        foreach ([self::T_RECORDATORIO, self::T_CONFIRMACION, self::T_AVISO] as $nombre) {
            PlantillasDelTenant::registrar($meta, $nombre, $estado);
        }
    }

    /**
     * **Un solo `Http::fake()` por test.**
     *
     * ⚠️ `Http::fake()` acumula stubs: un segundo llamado no reemplaza al primero,
     * el doble nuevo nunca se usa y el test queda verde sin probar nada. Ya hizo
     * pasar tres tests de fallo en T-026. Por eso lo que contesta Meta se decide
     * acá adentro **según la cuenta que nombra la URL**, nunca según el orden de
     * las llamadas: es lo único que permite afirmar el aislamiento de verdad, con
     * las dos PyMEs recibiendo respuestas distintas en la misma corrida.
     *
     * @param  array<string,string>  $estadoPorWaba  Estado que Meta reporta por cuenta.
     * @param  array<int,string>  $rompe  Cuentas para las que Meta contesta un 500.
     */
    private function fakeDeMeta(array $estadoPorWaba, array $rompe = []): void
    {
        Http::fake(['graph.facebook.com/*' => function ($request) use ($estadoPorWaba, $rompe) {
            $waba = $this->wabaDeLaUrl((string) $request->url());

            if (in_array($waba, $rompe, true)) {
                return Http::response([
                    'error' => ['code' => 190, 'message' => 'Error validating access token', 'type' => 'OAuthException'],
                ], 500);
            }

            // Una creación de plantilla. La tarea agendada no debería hacer
            // ninguna, pero si la hace el test la tiene que poder ver.
            if (strtoupper((string) $request->method()) === 'POST') {
                return Http::response(['id' => '1', 'status' => self::PENDIENTE, 'category' => 'UTILITY']);
            }

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

    // --------------------------------------------------------------- utilidades

    /** Qué cuenta nombra una URL de la Graph API, o `''` si no nombra ninguna conocida. */
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

    /** @return array<int,string> Todas las URLs que se le pidieron a Meta. */
    private function pedidosAMeta(): array
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
     * Las cuentas que la tarea fue a consultar, sin repetir y como **string**.
     *
     * ⚠️ El `strval` no es adorno: PHP convierte a `int` toda clave de array que
     * parezca un entero, y un `(int)` sobre estos identificadores los vuelve
     * incomparables con el string que guarda la integración.
     *
     * @return array<int,string>
     */
    private function cuentasConsultadas(): array
    {
        $wabas = [];

        foreach ($this->pedidosAMeta() as $url) {
            $waba = $this->wabaDeLaUrl($url);

            $wabas[$waba !== '' ? $waba : $url] = true;
        }

        $lista = array_map('strval', array_keys($wabas));

        sort($lista);

        return $lista;
    }

    /** La tarea, tal como quedó en el scheduler del proyecto. */
    private function tareaProgramada(): ?object
    {
        return collect(app(Schedule::class)->events())
            ->first(fn ($evento) => str_contains((string) $evento->command, self::COMANDO));
    }

    /** @return array<int,array{nivel:string,mensaje:string,contexto:array<string,mixed>}> */
    private function erroresRegistrados(): array
    {
        return array_values(array_filter(
            $this->registros,
            static fn (array $r): bool => in_array($r['nivel'], ['error', 'critical', 'alert', 'emergency'], true),
        ));
    }

    /** @return array<string,string> Los estados del tenant, en mayúsculas y ordenados. */
    private function estadosDe(Integration $meta): array
    {
        $estados = array_map(
            static fn ($e): string => strtoupper((string) $e),
            PlantillasDelTenant::estados($meta),
        );

        ksort($estados);

        return $estados;
    }

    /** @return array<string,string> Las tres en un mismo estado, para comparar. */
    private function lasTresEn(string $estado): array
    {
        return [
            self::T_AVISO => $estado,
            self::T_CONFIRMACION => $estado,
            self::T_RECORDATORIO => $estado,
        ];
    }

    // ------------------------------------------- 1 · la tarea está agendada

    /**
     * Una tarea que existe y que nadie corre deja a la PyME exactamente igual de
     * trabada que hoy: el operador vuelve a tener que adivinar cuándo destrabarla,
     * y mientras adivina se queman los turnos que cruzan la ventana de t-24h.
     */
    public function test_la_sincronizacion_de_plantillas_esta_agendada(): void
    {
        $programadas = collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains((string) $evento->command, self::COMANDO));

        $this->assertCount(1, $programadas,
            'El comando '.self::COMANDO.' no está en el scheduler. Sin eso, la PyME cuyas plantillas '
            .'Meta aprobó sigue trabada hasta que a alguien se le ocurra correr algo a mano, y no hay '
            .'ningún webhook que le avise a nadie.');
    }

    /**
     * § 7 · Mover la cadencia tiene que ser **una línea**.
     *
     * Cada cuánto corre es cuánto tiempo una PyME puede quedar sin poder mandar
     * después de que Meta ya la aprobó, y eso se traduce directo en turnos que
     * cruzan la ventana de t-24h sin recordatorio. Cuando el piloto muestre que el
     * número elegido es mucho o es un gasto inútil de cuota de Meta, cambiarlo no
     * puede exigir buscar dónde estaba escrito.
     *
     * ⚠️ **El valor no se afirma acá**, y es a propósito: ningún documento fija la
     * cadencia y no la invento. Lo que se afirma es que existe una constante con
     * nombre y que **el scheduler deriva de ella**. Si alguien la mueve y deja el
     * `everyTenMinutes()` abajo, este test se pone rojo.
     *
     * ⚠️ La cadencia se mide **corriendo la expresión de cron**, no comparándola
     * como texto: «cada 15 minutos» y «a la hora en punto» se escriben con
     * expresiones de forma distinta, y cuál corresponde depende del número que se
     * ratifique. Comparar el texto le fijaría al implementador la forma —y con
     * ella la decisión— por la ventana.
     */
    public function test_la_cadencia_de_la_sincronizacion_vive_en_una_constante_con_nombre(): void
    {
        $minutos = SincronizarPlantillas::MINUTOS_ENTRE_CORRIDAS;

        $this->assertIsInt($minutos,
            'La cadencia tiene que ser un entero de minutos, para poder derivar el scheduler de ella.');

        $this->assertGreaterThan(0, $minutos,
            'Una cadencia de cero o negativa no describe ninguna corrida.');

        $tarea = $this->tareaProgramada();

        $this->assertNotNull($tarea,
            'Precondición: la tarea tiene que estar en el scheduler para poder medirle la cadencia.');

        $cron = new CronExpression((string) $tarea->expression);

        $anclaje = new \DateTime('2026-09-01 00:00:00', new \DateTimeZone('UTC'));
        $primera = $cron->getNextRunDate($anclaje, 0, true);
        $segunda = $cron->getNextRunDate($primera, 0, false);

        $this->assertSame(
            $minutos,
            (int) round(($segunda->getTimestamp() - $primera->getTimestamp()) / 60),
            'El scheduler no corre cada `MINUTOS_ENTRE_CORRIDAS` minutos: cambiar la constante no '
            .'alcanza para cambiar cuándo corre, que es justo lo que la constante existe para evitar. '
            .'Expresión programada: '.$tarea->expression
        );
    }

    // ------------------------------------- 2 · la PyME se destraba sola

    /**
     * **El test que prueba la decisión.**
     *
     * Meta aprobó las tres —lo normal a las pocas horas del alta— y nadie en el
     * sistema se enteró, porque no hay webhook. La corrida agendada tiene que
     * dejar `todasAprobadas()` en `true` **sin que nadie corra nada a mano**: es
     * literalmente el freno que `EnviarRecordatorios` consulta para decidir si al
     * cliente le sale el recordatorio t-24h.
     */
    public function test_la_corrida_agendada_destraba_sola_a_la_pyme_que_meta_ya_aprobo(): void
    {
        $this->fakeDeMeta([self::WABA_B => self::APROBADA]);
        $this->conLasTresEn($this->metaB, self::PENDIENTE);

        $this->assertFalse(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Precondición: la PyME tiene que arrancar trabada, con las tres en `PENDING`.');

        Artisan::call(self::COMANDO);

        $this->assertSame($this->lasTresEn(self::APROBADA), $this->estadosDe($this->metaB),
            'La corrida no registró lo que Meta contestó: las plantillas siguen figurando `PENDING` '
            .'aunque Meta ya las aprobó.');

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($this->metaB),
            'La PyME sigue trabada después de la corrida agendada. `EnviarRecordatorios` le va a '
            .'seguir frenando todos los recordatorios sin ningún error que lo avise, y cada turno que '
            .'cruce la ventana de t-24h mientras tanto no lo recupera nada.');
    }

    // -------------------------------- 3 · no se gasta cuota en la que ya está lista

    /**
     * Es **una llamada a Meta por PyME en cada corrida**, y en la que ya tiene las
     * tres aprobadas no hay nada que sincronizar: el estado `APPROVED` es terminal
     * para lo que decide el envío. El proyecto ya cuida esa cuota en la
     * conciliación —bajó a 15 minutos justamente midiendo ese costo—, y acá el
     * gasto es contra la misma API de Meta que usa el camino crítico del cliente.
     *
     * ⚠️ El canario no es adorno: sin él, «no consultó a A» sería cierto por
     * omisión el día que la tarea no haga absolutamente nada.
     */
    public function test_no_le_consulta_a_meta_por_la_pyme_que_ya_tiene_las_tres_aprobadas(): void
    {
        $this->fakeDeMeta([self::WABA_A => self::APROBADA, self::WABA_B => self::APROBADA]);

        $this->conLasTresEn($this->metaA, self::APROBADA);
        $this->conLasTresEn($this->metaB, self::PENDIENTE);

        Artisan::call(self::COMANDO);

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Canario: la sincronización tiene que haber corrido sobre la PyME que sí tenía algo '
            .'pendiente, si no «no consultó por la otra» es cierto por omisión y este test no prueba '
            .'nada.');

        $this->assertSame([self::WABA_B], $this->cuentasConsultadas(),
            'Se le consultó a Meta por una PyME que ya tenía las tres plantillas aprobadas: es una '
            .'llamada por tenant en cada corrida para no enterarse de nada. Cuentas consultadas: '
            .implode(', ', $this->cuentasConsultadas()));
    }

    // --------------------------------------- 4 · aislamiento por tenant · RNF-01

    /**
     * RNF-01 · Cada PyME se consulta contra **su** WABA.
     *
     * Es la misma forma del bug del emisor que ya apareció dos veces en este
     * proyecto: leer `config('services.meta.waba_id')` —que acá apunta a la cuenta
     * del tenant A— haría que la PyME B quede marcada con el estado de las
     * plantillas de otro cliente. Si lo copia como `APPROVED`, el envío muere en
     * `template not found`; si lo copia como `PENDING`, queda trabada para siempre
     * por un motivo que no es suyo.
     *
     * Por eso Meta contesta **distinto para cada cuenta en la misma corrida**: es
     * la única forma de que el test note la diferencia.
     */
    public function test_cada_pyme_se_sincroniza_contra_su_propia_cuenta_de_whatsapp(): void
    {
        $this->fakeDeMeta([self::WABA_A => self::PENDIENTE, self::WABA_B => self::APROBADA]);

        $this->conLasTresEn($this->metaA, self::PENDIENTE);
        $this->conLasTresEn($this->metaB, self::PENDIENTE);

        $this->assertNotSame((string) $this->tenantA->id, (string) $this->tenantB->id,
            'Precondición: los dos tenants tienen que ser distintos. (Comparación como string: un '
            .'`(int)` sobre un UUID devuelve 1 para todos.)');

        Artisan::call(self::COMANDO);

        $this->assertSame([self::WABA_A, self::WABA_B], $this->cuentasConsultadas(),
            'La tarea no consultó exactamente una cuenta por PyME. Si falta la de B y sobra la de A, '
            ."el `waba_id` sale de config('services.meta.waba_id') y no de la integración. Cuentas: "
            .implode(', ', $this->cuentasConsultadas()));

        $this->assertSame($this->lasTresEn(self::APROBADA), $this->estadosDe($this->metaB),
            'La PyME B no quedó con el estado que Meta reportó para **su** cuenta.');

        $this->assertSame($this->lasTresEn(self::PENDIENTE), $this->estadosDe($this->metaA),
            'La PyME A quedó marcada con un estado que Meta no reportó para su cuenta: se le copió el '
            .'de otro cliente. Marcarla aprobada sin estarlo hace que el recordatorio muera en '
            .'`template not found`, y el síntoma aparece recién cuando el turno no se avisó.');
    }

    // -------------------- 5 · una PyME que falla no frena a las demás · RNF-03

    /**
     * RNF-03 · La PyME cuya consulta a Meta falla **no puede dejar sin sincronizar
     * a las demás**.
     *
     * Es el mismo patrón que `ConciliarAgendamientos::conciliarTenant()`: un token
     * vencido o una caída de Meta es un problema de **una** cuenta. Si la
     * excepción sube y corta la corrida, un solo cliente con la integración rota
     * deja a toda la cartera trabada en `PENDING`, y el síntoma —«no le llega nada
     * a nadie»— no menciona ni al tenant ni a Meta.
     *
     * ⚠️ La PyME que falla es la que se procesa **primero** (ver `setUp`): si
     * fuera la última, una implementación que corta en el primer error pasaría
     * este test igual.
     */
    public function test_la_pyme_cuya_consulta_falla_no_deja_sin_sincronizar_a_las_demas(): void
    {
        $this->fakeDeMeta([self::WABA_B => self::APROBADA], rompe: [self::WABA_A]);

        $this->conLasTresEn($this->metaA, self::PENDIENTE);
        $this->conLasTresEn($this->metaB, self::PENDIENTE);

        Artisan::call(self::COMANDO);

        $this->assertContains(self::WABA_A, $this->cuentasConsultadas(),
            'Precondición: la cuenta que falla tiene que haberse consultado, si no el doble que simula '
            .'el error nunca se usó y este test corre contra el camino feliz sin probar nada.');

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($this->metaB),
            'El fallo de una PyME dejó sin sincronizar a la siguiente: un token vencido de un solo '
            .'cliente traba a toda la cartera, y nadie se entera hasta que no sale ningún recordatorio.');

        $this->assertSame($this->lasTresEn(self::PENDIENTE), $this->estadosDe($this->metaA),
            'La PyME cuya consulta falló cambió de estado. Un error de Meta no autoriza a concluir '
            .'nada sobre sus plantillas.');
    }

    /**
     * RNF-03 · Ningún camino termina en silencio.
     *
     * Una PyME que no se pudo sincronizar sigue trabada, y si además nadie lo
     * registra el operador no tiene ni el dato para ir a mirarla. Es la ⚠️ que
     * quedó abierta del ciclo anterior: el camino obvio deja el fallo tan mudo
     * como el bug que veníamos a cerrar.
     *
     * ⚠️ El código del registro se afirma **por fragmento**, no textual: el
     * implementador elige el nombre; lo que no puede es no registrarlo, ni
     * registrarlo sin decir de qué tenant se trata.
     *
     * ⚠️ No se afirma nada sobre el `access_token`, ni parcialmente: un test que
     * lo afirme lo imprime en el diff cuando falla.
     */
    public function test_el_fallo_de_la_sincronizacion_de_una_pyme_queda_registrado(): void
    {
        $this->fakeDeMeta([self::WABA_B => self::APROBADA], rompe: [self::WABA_A]);

        $this->conLasTresEn($this->metaA, self::PENDIENTE);
        $this->conLasTresEn($this->metaB, self::PENDIENTE);

        Artisan::call(self::COMANDO);

        $delTenantQueFalla = array_values(array_filter(
            $this->erroresRegistrados(),
            fn (array $r): bool => (string) ($r['contexto']['tenant_id'] ?? '') === (string) $this->tenantA->id,
        ));

        $this->assertNotSame([], $delTenantQueFalla,
            'No quedó registrado ningún error que nombre al tenant cuya sincronización falló. Esa PyME '
            .'sigue trabada y el operador no tiene ni el dato para ir a mirarla: es el mismo fallo '
            .'silencioso que RNF-03 prohíbe. Errores registrados: '
            .json_encode(array_column($this->erroresRegistrados(), 'mensaje')));

        $registro = $delTenantQueFalla[0];

        $this->assertMatchesRegularExpression(
            '/PLANTILLA|SINCRON/i',
            (string) ($registro['contexto']['codigo'] ?? '').' '.$registro['mensaje'],
            'El registro no dice que lo que falló fue la sincronización de plantillas, así que en el '
            .'log no se distingue de cualquier otro error de esa PyME.'
        );
    }

    /**
     * Una tarea automática **solo lee**.
     *
     * Volver a pedir una plantilla que ya existe devuelve error de nombre
     * duplicado y la dejaría registrada como `REJECTED`: una tarea que corre sola
     * cada pocos minutos y que pueda crear plantillas en la cuenta de un cliente
     * es un riesgo que nadie pidió correr, y el daño sería silencioso y repetido.
     *
     * ⚠️ Canario: sin él, «no creó nada» es cierto por omisión mientras la tarea
     * no haga absolutamente nada.
     */
    public function test_la_sincronizacion_no_crea_plantillas_solo_lee_el_estado(): void
    {
        $this->fakeDeMeta([self::WABA_B => self::APROBADA]);
        $this->conLasTresEn($this->metaB, self::PENDIENTE);

        Artisan::call(self::COMANDO);

        $this->assertTrue(PlantillasDelTenant::todasAprobadas($this->metaB),
            'Canario: la sincronización tiene que haber corrido, si no «no creó nada» es cierto por '
            .'omisión y este test no prueba nada.');

        // ⚠️ `->all()` no es adorno: `Http::recorded()` devuelve una `Collection`
        // —incluso vacía— y `array_filter()` exige un `array`. Sin esto el test
        // muere con un `TypeError` **después** del canario, así que parecía un
        // rojo de implementación cuando era un defecto propio.
        $escrituras = array_values(array_filter(
            Http::recorded()->all(),
            static fn (array $par): bool => strtoupper((string) $par[0]->method()) !== 'GET'
                && str_contains($par[0]->url(), 'graph.facebook.com'),
        ));

        $this->assertSame([], array_map(static fn (array $par): string => $par[0]->url(), $escrituras),
            'La tarea agendada le escribió a Meta. Volver a pedir una plantilla que ya existe devuelve '
            .'error de nombre duplicado y la deja registrada como rechazada: la corrida automática que '
            .'venía a destrabar al cliente lo rompería, sola y en repetición.');
    }
}
