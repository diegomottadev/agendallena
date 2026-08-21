<?php

namespace Tests\Feature;

use App\Meta\AltaDeCuenta;
use App\Meta\PlantillasDelTenant;
use App\Meta\PlantillasDeWhatsApp;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T-050 · criterio 4 · T-037 no le manda plantillas a una cuenta que todavía no
 * las tiene aprobadas — y lo deja registrado.
 *
 * Con una WABA por PyME, **las plantillas se aprueban por cuenta**: el cliente
 * nuevo tiene su alta hecha y sus plantillas creadas, pero Meta puede tardar en
 * aprobarlas y eso no lo controlamos. Mandar igual es tirar el recordatorio
 * contra un `template not found`, que es justo el fallo silencioso que RNF-03
 * prohíbe: el turno queda sin avisar y nadie se entera hasta que el cliente no
 * aparece.
 *
 * Lo que se prueba acá es el **freno y su registro**. Que la plantilla salga bien
 * cuando corresponde ya está en `RecordatorioT24hTest`.
 *
 * ## ⚠️ Decisiones que tuve que tomar para poder escribir el test
 *
 * | Elección | Por qué |
 * | :-- | :-- |
 * | El estado "aprobado" se monta **corriendo el alta** con Meta contestando `APPROVED` | Evita inventar un setter del registro: el único camino que el ticket describe para que un estado exista es el alta |
 * | El `codigo` del log se afirma **por fragmento** (`PLANTILLA`), no textual | El ticket pide "lo registra" y no fija el código. El implementador elige el nombre; lo que no puede es no registrarlo |
 * | El freno **no consume el candado** de `notification_logs` | ✅ Era la decisión de producto que faltaba y que por eso no se afirmaba acá. Diego la tomó (§ 2 de `decisiones-tomadas.md`): un recordatorio frenado por plantillas sin aprobar **no gasta** el único `(booking_id, type)`, así que el turno se reintenta en la corrida siguiente y sale apenas Meta aprueba. Los dos últimos tests del archivo la fijan |
 *
 * ⚠️ **El caso `waba_id` faltante también entra acá** (criterio 5), en su segunda
 * lectura: "al intentar mandar". La primera —el alta— está en
 * `AltaDeCuentaDeWhatsAppTest`.
 */
class RecordatorioConPlantillaNoAprobadaTest extends TestCase
{
    use RefreshDatabase;

    private const COMANDO = 'recordatorios:enviar';

    private const WABA_A = '1360872979217448';

    private const WABA_B = '9925471068433129';

    private const PHONE_A = '1053554814514902';

    private const PHONE_B = '7742019983365574';

    private const TELEFONO_A = '5493764278402';

    private const TELEFONO_B = '5491133445566';

    /**
     * **UTC−5 todo el año.** No es Buenos Aires a propósito: BA y Santiago
     * comparten offset parte del año y ya hicieron pasar por casualidad un test
     * de husos en T-031. Bogotá no coincide con UTC ningún día.
     */
    private const TZ = 'America/Bogota';

    /** El "ahora" de todos los tests, en UTC. */
    private const AHORA = '2026-08-20 12:00:00';

    /** @var array<int,array{nivel:string,mensaje:string,contexto:array<string,mixed>}> */
    private array $registros = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC'));

        // Igual que en `EmisorPorTenantTest`: el global apunta a la cuenta del
        // tenant A, para que ningún envío de B pueda pasar leyendo la config.
        config()->set('services.meta.waba_id', self::WABA_A);

        Log::listen(function ($m) {
            $this->registros[] = ['nivel' => $m->level, 'mensaje' => $m->message, 'contexto' => $m->context];
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        $this->registros = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------ montaje

    private function pyme(string $nombre, string $slug, string $phoneNumberId, ?string $wabaId): Tenant
    {
        $tenant = Tenant::create([
            'name' => $nombre, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        $settings = ['verify_token' => 'tok-'.$slug];

        if ($wabaId !== null) {
            $settings['waba_id'] = $wabaId;
        }

        Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId, 'access_token' => 'token-'.$slug,
            'settings' => $settings, 'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            // Distinto por tenant: `integrations` tiene un único
            // (provider, account_identifier).
            'account_identifier' => 'duenio+'.$slug.'@negocio.com', 'access_token' => 'ya29',
            'refresh_token' => '1//r', 'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        return $tenant;
    }

    /** Un turno agendado que entra en la ventana de t-24h. */
    private function turnoEnVentana(Tenant $tenant, string $telefono, string $nombre = 'Ana'): Booking
    {
        return TenantContext::runAs((string) $tenant->id, function () use ($tenant, $telefono, $nombre) {
            $conversacion = Conversation::create([
                'tenant_id' => $tenant->id,
                'user_phone' => $telefono,
                'current_state' => 'BOOKED',
                'state_version' => 4,
                'context_data' => ['nombre' => $nombre],
                'last_interaction_at' => now(),
            ]);

            $google = Integration::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->first();

            $inicio = CarbonImmutable::now('UTC')->addHours(24);

            return Booking::create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversacion->id,
                'integration_id' => $google->id,
                'external_event_id' => 'evt_google_'.$tenant->slug,
                'client_name' => $nombre,
                'client_phone' => $telefono,
                'service_name' => 'Corte de pelo',
                'start_time' => $inicio,
                'end_time' => $inicio->addMinutes(30),
                'status' => Booking::ESTADO_AGENDADO,
                'attendance' => Booking::ASISTENCIA_PENDIENTE,
            ]);
        });
    }

    /**
     * **Un solo `Http::fake()` por test**, con las tres respuestas adentro.
     *
     * ⚠️ `Http::fake()` acumula stubs: un segundo llamado para simular que Meta
     * todavía no aprobó no reemplazaría al primero, el doble nuevo nunca se usaría
     * y el test quedaría verde sin haber probado nada (pasó tres veces en T-026).
     * Por eso el estado de aprobación se decide acá, por WABA.
     *
     * @param  array<string,string>  $estadoPorWaba
     */
    private function fakes(array $estadoPorWaba = []): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'id' => 'evt_google_123',
                'status' => 'confirmed',
                'extendedProperties' => ['private' => ['origen' => 'agendallena']],
            ]),

            'graph.facebook.com/*' => function (Request $request) use ($estadoPorWaba) {
                if (str_contains($request->url(), 'message_templates')) {
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
                }

                return Http::response(['messages' => [['id' => 'wamid.'.uniqid('', true)]]]);
            },
        ]);
    }

    private function darDeAlta(Tenant $tenant): void
    {
        $meta = Integration::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->firstOrFail();

        TenantContext::runAs((string) $tenant->id, fn () => app(AltaDeCuenta::class)->crearPlantillas($meta));
    }

    private function correrLaTarea(): void
    {
        $this->artisan(self::COMANDO)->assertSuccessful();
    }

    // --------------------------------------------------------------- utilidades

    /**
     * Los mensajes que **se le mandaron al cliente**, por número emisor.
     *
     * Se filtra `message_templates` a mano: crear una plantilla también es un POST
     * a `graph.facebook.com` y contarlo como envío haría pasar el test sin que
     * nadie haya recibido nada.
     *
     * @return array<int,string>
     */
    private function enviosAClientes(): array
    {
        $urls = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com') && str_contains($req->url(), '/messages')) {
                $urls[] = $req->url();
            }
        }

        return $urls;
    }

    private function seEnvioDesde(string $phoneNumberId): bool
    {
        foreach ($this->enviosAClientes() as $url) {
            if (str_contains($url, '/'.$phoneNumberId.'/messages')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los registros cuyo `codigo` contiene este fragmento.
     *
     * @return array<int,array{nivel:string,mensaje:string,contexto:array<string,mixed>}>
     */
    private function registrosConCodigo(string $fragmento): array
    {
        return array_values(array_filter($this->registros, function (array $r) use ($fragmento) {
            $codigo = $r['contexto']['codigo'] ?? null;

            return is_string($codigo) && str_contains($codigo, $fragmento);
        }));
    }

    /** @return array<int,string> Todos los códigos registrados, para el mensaje de falla. */
    private function codigosRegistrados(): array
    {
        $codigos = [];

        foreach ($this->registros as $r) {
            if (isset($r['contexto']['codigo']) && is_string($r['contexto']['codigo'])) {
                $codigos[] = $r['contexto']['codigo'];
            }
        }

        return $codigos;
    }

    // ------------------------------------- criterio 4 · el freno y su registro

    /**
     * Plantillas creadas pero **todavía sin aprobar**: el recordatorio no sale.
     *
     * Es el estado normal del cliente recién dado de alta. El tiempo de aprobación
     * de Meta no lo controlamos, así que este estado dura lo que dure.
     */
    public function test_no_se_manda_el_recordatorio_si_las_plantillas_del_tenant_no_estan_aprobadas(): void
    {
        $this->fakes([self::WABA_B => 'PENDING']);

        $tenant = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_B, self::WABA_B);
        $this->darDeAlta($tenant);
        $this->turnoEnVentana($tenant, self::TELEFONO_B);

        $this->correrLaTarea();

        $this->assertSame([], $this->enviosAClientes(),
            'Se intentó mandar el recordatorio con las plantillas del tenant sin aprobar: Meta lo '
            .'rechaza con `template not found` y el turno queda sin aviso.');
    }

    /**
     * Y **queda registrado**: RNF-03 no admite que el turno desaparezca sin rastro.
     *
     * Un test aparte del anterior a propósito: "no se manda" y "se registra" son
     * dos comportamientos, y el segundo es el que hace la diferencia entre un
     * freno y un silencio.
     */
    public function test_el_recordatorio_frenado_por_plantilla_pendiente_queda_registrado(): void
    {
        $this->fakes([self::WABA_B => 'PENDING']);

        $tenant = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_B, self::WABA_B);
        $this->darDeAlta($tenant);
        $booking = $this->turnoEnVentana($tenant, self::TELEFONO_B);

        $this->correrLaTarea();

        // ⚠️ El código exacto lo elige el implementador: el ticket pide "lo
        // registra" y no lo nombra. Lo que se afirma es que exista un registro
        // que diga que fue por la plantilla, y de qué turno y de qué PyME.
        $registros = $this->registrosConCodigo('PLANTILLA');

        $this->assertNotEmpty($registros,
            'El recordatorio no salió y no quedó ningún registro que diga que fue porque las '
            .'plantillas del tenant no están aprobadas. Eso es un fallo en silencio (RNF-03): el '
            .'turno queda sin avisar y nadie se entera. Códigos registrados: '
            .implode(', ', $this->codigosRegistrados()));

        $registro = $registros[0];

        $this->assertContains($registro['nivel'], ['warning', 'error'],
            'El freno se registró por debajo de warning: US-24 lee los niveles altos.');

        // `tenant_id` es UUID: se compara **como string**. Un `(int)` sobre un
        // UUID devuelve 1 para todos y esta aserción pasaría siempre.
        $this->assertSame((string) $tenant->id, (string) ($registro['contexto']['tenant_id'] ?? ''),
            'El registro no dice de qué PyME es el turno que no se avisó.');

        $this->assertSame((string) $booking->id, (string) ($registro['contexto']['booking_id'] ?? ''),
            'El registro no dice qué turno quedó sin recordatorio.');
    }

    /**
     * La otra mitad: con las plantillas aprobadas, el recordatorio **sí** sale.
     *
     * ⚠️ Sin este test, el criterio 4 se cumple con un freno que bloquea todos los
     * envíos para siempre y la suite quedaría verde. Es el guardia del criterio,
     * no un caso feliz decorativo.
     */
    public function test_el_recordatorio_sale_cuando_las_plantillas_del_tenant_estan_aprobadas(): void
    {
        $this->fakes([self::WABA_B => 'APPROVED']);

        $tenant = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_B, self::WABA_B);
        $this->darDeAlta($tenant);
        $this->turnoEnVentana($tenant, self::TELEFONO_B);

        $this->correrLaTarea();

        $this->assertTrue($this->seEnvioDesde(self::PHONE_B),
            'Las plantillas del tenant están aprobadas y el recordatorio no salió igual: el freno '
            .'del criterio 4 está bloqueando a un cliente que ya puede recibir. Envíos: '
            .implode(', ', $this->enviosAClientes()));
    }

    /**
     * El estado de aprobación es **por tenant**: que uno esté esperando a Meta no
     * puede frenar los recordatorios del otro, ni al revés.
     *
     * Es el criterio 1 visto desde el envío: con un estado global, los dos turnos
     * salen o no sale ninguno.
     */
    public function test_la_plantilla_pendiente_de_un_tenant_no_frena_los_recordatorios_del_otro(): void
    {
        $this->fakes([self::WABA_A => 'APPROVED', self::WABA_B => 'PENDING']);

        $tenantA = $this->pyme('Peluquería Sur', 'peluqueria', self::PHONE_A, self::WABA_A);
        $tenantB = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_B, self::WABA_B);

        $this->darDeAlta($tenantA);
        $this->darDeAlta($tenantB);

        $this->turnoEnVentana($tenantA, self::TELEFONO_A, 'Ana');
        $this->turnoEnVentana($tenantB, self::TELEFONO_B, 'Bruno');

        $this->correrLaTarea();

        $this->assertTrue($this->seEnvioDesde(self::PHONE_A),
            'El tenant A tiene sus plantillas aprobadas y su recordatorio no salió: el estado se '
            .'está leyendo global y lo frenó el tenant B. Envíos: '.implode(', ', $this->enviosAClientes()));

        $this->assertFalse($this->seEnvioDesde(self::PHONE_B),
            'El tenant B tiene las plantillas pendientes y su recordatorio salió igual: el estado '
            .'de aprobación se está leyendo de otro tenant.');

        $this->assertCount(1, $this->enviosAClientes(),
            'Salió más de un mensaje: solo el tenant con plantillas aprobadas tenía que recibir.');
    }

    // ------------------- criterio 5 · sin cuenta cargada, tampoco en silencio

    /**
     * La PyME a la que nunca le cargaron el `waba_id`: no se le puede mandar una
     * plantilla, y el turno no puede quedar sin rastro por eso.
     *
     * ⚠️ Es la segunda lectura del criterio 5 —"al intentar mandar"—; la primera,
     * el alta, está en `AltaDeCuentaDeWhatsAppTest`. El criterio no distingue las
     * dos, así que cubro las dos.
     */
    public function test_un_tenant_sin_waba_id_no_manda_el_recordatorio_y_queda_registrado(): void
    {
        $this->fakes();

        $tenant = $this->pyme('Estudio Centro', 'estudio', self::PHONE_B, null);
        $booking = $this->turnoEnVentana($tenant, self::TELEFONO_B);

        $this->correrLaTarea();

        $this->assertSame([], $this->enviosAClientes(),
            'Se intentó mandar una plantilla sin tener la cuenta del tenant cargada: lo que vuelve '
            .'de Meta es un error de la plataforma que no dice qué falta.');

        $registros = array_values(array_filter($this->registros, function (array $r) use ($booking) {
            return in_array($r['nivel'], ['warning', 'error'], true)
                && (string) ($r['contexto']['booking_id'] ?? '') === (string) $booking->id;
        }));

        $this->assertNotEmpty($registros,
            'El turno de una PyME sin `waba_id` quedó sin recordatorio y sin registro. Códigos '
            .'registrados: '.implode(', ', $this->codigosRegistrados()));
    }

    /**
     * ⚠️ **La rama laxa.** Un tenant con `waba_id` cargado al que **nunca se le
     * corrió el alta**: no tiene ninguna plantilla registrada.
     *
     * Es el estado real de toda PyME entre que alguien le carga la cuenta a mano
     * y alguien se acuerda de correr `cuenta:dar-de-alta`. No es hipotético: es
     * exactamente la ventana que abrió el bug de anoche, cuando `AltaDeCuenta`
     * no tenía llamador en producción.
     *
     * "No sé nada de su cuenta" y "sé que está aprobada" no son lo mismo, y el
     * criterio 4 de T-050 pide que **no se intente el envío**. Sin plantillas
     * creadas en la WABA, mandar es un `template not found` garantizado: el turno
     * queda sin aviso y nadie se entera hasta que el cliente no aparece.
     */
    public function test_un_tenant_sin_ninguna_plantilla_registrada_no_manda_el_recordatorio(): void
    {
        $this->fakes();

        // Sin `darDeAlta()`: la cuenta está cargada y las plantillas no existen.
        $tenant = $this->pyme('Peluqueria Sur', 'peluqueria', self::PHONE_B, self::WABA_B);
        $this->turnoEnVentana($tenant, self::TELEFONO_B);

        $this->correrLaTarea();

        $this->assertSame([], $this->enviosAClientes(),
            'Se intentó mandar el recordatorio a una PyME que no tiene ninguna plantilla creada en '
            .'su WABA. Meta lo rechaza con `template not found` y el turno queda sin aviso.');
    }

    /**
     * Y **queda registrado**, por la misma razón que el freno por plantilla
     * pendiente: un freno que no se registra es indistinguible de un silencio, y
     * el operador necesita saber que a esta PyME le falta correr el alta.
     */
    public function test_el_freno_por_alta_sin_correr_queda_registrado(): void
    {
        $this->fakes();

        $tenant = $this->pyme('Peluqueria Sur', 'peluqueria', self::PHONE_B, self::WABA_B);
        $booking = $this->turnoEnVentana($tenant, self::TELEFONO_B);

        $this->correrLaTarea();

        $registros = array_values(array_filter($this->registros, function (array $r) use ($booking) {
            return in_array($r['nivel'], ['warning', 'error'], true)
                && (string) ($r['contexto']['booking_id'] ?? '') === (string) $booking->id;
        }));

        $this->assertNotEmpty($registros,
            'El turno de una PyME sin plantillas creadas quedó sin recordatorio y sin registro. '
            .'Códigos registrados: '.implode(', ', $this->codigosRegistrados()));
    }

    // ------ decisión § 2 · el freno por plantilla pendiente no gasta el candado

    /**
     * Un recordatorio frenado porque Meta todavía no aprobó las plantillas
     * **no deja fila en `notification_logs`**.
     *
     * El único `(booking_id, type)` de esa tabla es el candado anti-duplicados, y
     * es de un solo uso: la fila que se escribe es la que impide que la corrida
     * siguiente vuelva a mandar. Si el freno la escribiera, este turno quedaría
     * marcado como "ya avisado" sin que nadie haya recibido nada, y ninguna
     * corrida posterior lo volvería a mirar aunque Meta aprobara cinco minutos
     * después.
     *
     * El costo real: el cliente de la PyME recién dada de alta se queda sin
     * ningún recordatorio de su primer día de uso, que es justo cuando la PyME
     * está decidiendo si el producto sirve.
     *
     * ⚠️ Este test mira el **estado intermedio**. El que prueba la decisión
     * completa es el que sigue.
     */
    public function test_el_freno_por_plantilla_pendiente_no_consume_el_candado(): void
    {
        $this->fakes([self::WABA_B => 'PENDING']);

        $tenant = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_B, self::WABA_B);
        $this->darDeAlta($tenant);
        $booking = $this->turnoEnVentana($tenant, self::TELEFONO_B);

        $this->correrLaTarea();

        $this->assertSame(0, $this->filasDeCandado($booking),
            'El recordatorio no salió y sin embargo quedó tomado el candado `(booking_id, '
            .'reminder_24h)`. Ese único es de un solo uso: con la fila escrita, ninguna corrida '
            .'posterior vuelve a mandar y el turno queda sin recordatorio para siempre, aunque '
            .'Meta apruebe las plantillas cinco minutos después.');
    }

    /**
     * Y la otra mitad, que es la decisión de verdad: **cuando Meta aprueba, ese
     * mismo turno sí recibe su recordatorio**.
     *
     * Dos corridas de la tarea sobre el mismo turno dentro de la ventana: la
     * primera con las plantillas pendientes, la segunda con Meta ya aprobando.
     * Que el freno no gaste el candado solo importa por esto — lo que se compra
     * con no gastarlo es el reintento.
     *
     * Sin este test, el anterior se cumple con un freno que no escribe nada y
     * tampoco reintenta nunca, y el cliente queda igual de sin avisar.
     */
    public function test_el_turno_frenado_recibe_su_recordatorio_cuando_meta_aprueba_despues(): void
    {
        $this->fakes([self::WABA_B => 'PENDING']);

        $tenant = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_B, self::WABA_B);
        $this->darDeAlta($tenant);
        $booking = $this->turnoEnVentana($tenant, self::TELEFONO_B);

        // Primera corrida: Meta todavía no contestó. No sale nada, y es correcto.
        $this->correrLaTarea();

        $this->assertSame([], $this->enviosAClientes(),
            'Salió el recordatorio con las plantillas del tenant sin aprobar: el escenario que '
            .'este test necesita montar no se está dando y lo que venga después no prueba nada.');

        // Meta aprueba. Se registra por el mismo camino que usa el alta.
        $this->metaAprueba($tenant);

        // Segunda corrida, el mismo turno, la misma ventana.
        $this->correrLaTarea();

        $this->assertTrue($this->seEnvioDesde(self::PHONE_B),
            'Meta aprobó las plantillas y el turno que se había frenado nunca recibió su '
            .'recordatorio: el freno le gastó el candado y lo dejó marcado como avisado sin '
            .'avisarlo. Es un cliente sin recordatorio de su primer turno. Envíos: '
            .implode(', ', $this->enviosAClientes()));

        $this->assertSame(1, $this->filasDeCandado($booking),
            'El turno tiene una cantidad de registros distinta de uno en `notification_logs`: si '
            .'es cero el recordatorio salió sin candado y la corrida siguiente lo manda de nuevo '
            .'—el cliente lo recibe dos veces y la PyME paga las dos plantillas—; si es más de '
            .'uno, el único de T-009 dejó de estar.');

        $this->assertCount(1, $this->enviosAClientes(),
            'El recordatorio salió más de una vez entre las dos corridas: el candado se toma '
            .'cuando el envío ocurre de verdad, no antes ni dos veces.');
    }

    /**
     * Meta aprobó las tres plantillas del tenant.
     *
     * ⚠️ Se registra por `PlantillasDelTenant::registrar()`, que es **el mismo
     * camino que usa el alta** para dejar asentado lo que Meta contestó. No se
     * vuelve a correr `crearPlantillas()` por dos razones: en la cuenta real las
     * plantillas ya existen y un segundo intento vuelve como nombre duplicado
     * (lo dice el docblock de `AltaDeCuenta::crearPlantillas`), y acá `Http::fake()`
     * **acumula** stubs, así que un segundo `fakes()` con `APPROVED` no
     * reemplazaría al primero: se seguiría usando el doble que contesta `PENDING`
     * y el test quedaría verde sin haber probado el reintento.
     */
    private function metaAprueba(Tenant $tenant): void
    {
        $meta = Integration::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->firstOrFail();

        foreach (PlantillasDeWhatsApp::NOMBRES as $nombre) {
            PlantillasDelTenant::registrar($meta, $nombre, PlantillasDelTenant::APROBADA);
        }
    }

    /**
     * Las filas del candado anti-duplicados de este turno.
     *
     * Se lee con `DB::table()` a propósito: `NotificationLog` lleva el Global
     * Scope de tenant y esto corre fuera de todo contexto. Además, contar sobre
     * la tabla cruda es lo que hace que la aserción hable del único de T-009 y no
     * de lo que el modelo deje ver.
     */
    private function filasDeCandado(Booking $booking): int
    {
        return DB::table('notification_logs')
            ->where('booking_id', $booking->id)
            ->where('type', NotificationLog::TIPO_RECORDATORIO_24H)
            ->count();
    }
}
