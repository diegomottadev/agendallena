<?php

namespace Tests\Feature;

use App\Conversacion\Interactivo\IdSellado;
use App\Conversacion\ListaDeHorarios;
use App\Jobs\ProcessMessageJob;
use App\Meta\NumeroDeWhatsApp;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T-029 · Control de colisión de horario al confirmar.
 *
 * Entre que el bot muestra la lista y el cliente toca un ítem pasan segundos, y
 * en un negocio con demanda **dos personas mirando la misma lista es el caso
 * normal**. Hoy `Reserva::agendar()` crea el evento sin volver a preguntarle a
 * Google y sin apartar nada: los dos lo obtienen.
 *
 * ## Cómo se ejercita la concurrencia sin hilos
 *
 * No hacen falta procesos reales. Lo que el criterio pide es que **el segundo
 * intento ocurra con el primero a mitad de camino**, y ese "a mitad de camino"
 * tiene un punto exacto y observable: el `POST` a Google para crear el evento.
 * Cuando esa petición está en vuelo, el primer cliente ya eligió su horario y
 * todavía no tiene turno.
 *
 * Por eso el doble de HTTP del endpoint de eventos es un *closure*: cuando el
 * primer cliente llega ahí, el closure ejecuta el mensaje del segundo cliente y
 * recién después contesta. Es la misma ventana que en producción abren dos
 * workers, montada de forma determinista.
 *
 * ## Un solo `Http::fake()` por test
 *
 * ⚠️ `Http::fake()` **acumula** stubs: llamarlo dos veces no reemplaza al
 * primero. Tres tests de fallo de T-026 pasaron sin probar nada por esto. Acá
 * las respuestas que tienen que cambiar entre llamadas se resuelven **dentro**
 * del mismo closure —la forma de `Http::sequence()` cuando el contenido de la
 * segunda respuesta recién se conoce después de la primera, porque depende del
 * horario que el cliente eligió— y cada test que depende de ese cambio
 * **afirma que la segunda respuesta se consumió**.
 */
class ColisionDeHorarioTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_ID_A = '1053554814514902';

    /** El número de la segunda PyME. No es substring del primero. */
    private const PHONE_ID_B = '7742019983365574';

    private const TEL_ANA = '5493764278402';

    private const TEL_BRUNO = '5491133445566';

    private const TEL_CARLA = '5492215566778';

    private const TZ = 'America/Argentina/Buenos_Aires';

    private Tenant $tenant;

    /** Excepción que haya lanzado un job ejecutado dentro de un doble de HTTP. */
    private ?\Throwable $errorAnidado = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Lunes 08:00. La grilla por defecto abre 09:00 y los horarios de hoy
        // todavía no pasaron.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 08:00', self::TZ));
        config()->set('services.meta.phone_number_id', self::PHONE_ID_A);

        $this->tenant = $this->pyme('Peluquería Sur', 'peluqueria', self::PHONE_ID_A);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------ escenario

    private function pyme(string $nombre, string $slug, string $phoneNumberId): Tenant
    {
        $tenant = Tenant::create([
            'name' => $nombre, 'slug' => $slug,
            'status' => 'active', 'timezone' => self::TZ,
        ]);

        Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId, 'access_token' => 'meta-'.$slug,
            'settings' => ['verify_token' => 'tok-'.$slug], 'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id, 'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => "duenio@{$slug}.com", 'access_token' => 'ya29-'.$slug,
            'refresh_token' => '1//r-'.$slug,
            'expires_at' => CarbonImmutable::parse('2027-01-01', 'UTC'),
            'status' => 'connected',
        ]);

        return $tenant;
    }

    /**
     * Declara los dobles. **Se llama una sola vez por test.**
     *
     * @param  \Closure|null  $ocupados  Devuelve los bloques ocupados que Google
     *                                   informa en *esta* llamada a `freeBusy`.
     * @param  \Closure|null  $alCrearEvento  Corre cuando el `POST` del evento
     *                                        está en vuelo, antes de contestar.
     */
    private function fakes(?\Closure $ocupados = null, ?\Closure $alCrearEvento = null): void
    {
        $evento = 0;

        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => function () use ($ocupados) {
                $bloques = $ocupados !== null ? $ocupados() : [];

                return Http::response(['calendars' => ['primary' => [
                    'busy' => array_map(fn (CarbonImmutable $i) => [
                        'start' => $i->utc()->toRfc3339String(),
                        'end' => $i->addHour()->utc()->toRfc3339String(),
                    ], $bloques),
                ]]]);
            },

            'www.googleapis.com/calendar/v3/calendars/primary/events*' => function () use (&$evento, $alCrearEvento) {
                $evento++;

                $respuesta = $alCrearEvento !== null ? $alCrearEvento($evento) : null;

                return $respuesta ?? Http::response(['id' => 'evt_google_'.$evento]);
            },

            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT_'.uniqid()]]]),
        ]);
    }

    // ----------------------------------------------------------- utilidades

    /** @return array<string,mixed> */
    private function texto(string $cuerpo, string $from): array
    {
        return $this->payload(['from' => $from, 'id' => 'wamid.'.uniqid(),
            'type' => 'text', 'text' => ['body' => $cuerpo]], self::PHONE_ID_A);
    }

    /** @return array<string,mixed> */
    private function toca(string $id, string $from, string $phoneNumberId = self::PHONE_ID_A): array
    {
        return $this->payload(['from' => $from, 'id' => 'wamid.'.uniqid(),
            'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => 'x']],
        ], $phoneNumberId);
    }

    /** @return array<string,mixed> */
    private function textoDe(string $cuerpo, string $from, string $phoneNumberId): array
    {
        return $this->payload(['from' => $from, 'id' => 'wamid.'.uniqid(),
            'type' => 'text', 'text' => ['body' => $cuerpo]], $phoneNumberId);
    }

    /** @return array<string,mixed> */
    private function payload(array $mensaje, string $phoneNumberId): array
    {
        return ['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => $phoneNumberId], 'messages' => [$mensaje]],
        ]]]]];
    }

    /** Corre un mensaje sin dejar que su excepción se pierda dentro de un doble. */
    private function correr(array $payload): void
    {
        try {
            (new ProcessMessageJob($payload))->handle();
        } catch (\Throwable $e) {
            $this->errorAnidado ??= $e;
        }
    }

    /** Relanza lo que haya fallado adentro de un doble, con su mensaje original. */
    private function sinErroresAnidados(): void
    {
        if ($this->errorAnidado !== null) {
            throw $this->errorAnidado;
        }
    }

    /**
     * Los cuerpos que se le mandaron a un teléfono, en orden.
     *
     * El destinatario se compara normalizado: Meta devuelve el `wa_id` con el 9
     * y acepta el envío sin él, así que el `to` de la petición nunca es igual al
     * `from` del webhook.
     *
     * @return array<int,array<string,mixed>>
     */
    private function mensajesA(string $telefono): array
    {
        $destino = NumeroDeWhatsApp::paraEnviar($telefono);
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'graph.facebook.com') && ($req->data()['to'] ?? null) === $destino) {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    /** El texto legible de un mensaje saliente, sea texto o interactivo. */
    private function cuerpoDe(array $mensaje): string
    {
        return $mensaje['text']['body'] ?? $mensaje['interactive']['body']['text'] ?? '';
    }

    /** @return array<int,array<string,mixed>> Los `POST` de creación de eventos. */
    private function eventosCreados(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), '/calendars/primary/events') && $req->method() === 'POST') {
                $out[] = $req->data();
            }
        }

        return $out;
    }

    /** Las llamadas a terceros en el orden en que ocurrieron. */
    private function secuenciaDeLlamadas(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, $res]) {
            if (str_contains($req->url(), 'freeBusy')) {
                $out[] = 'freebusy';
            } elseif (str_contains($req->url(), '/calendars/primary/events') && $req->method() === 'POST') {
                $out[] = 'evento';
            } elseif (str_contains($req->url(), 'graph.facebook.com')) {
                $out[] = 'meta';
            }
        }

        return $out;
    }

    private function llamadasAFreeBusy(): int
    {
        return count(array_filter($this->secuenciaDeLlamadas(), fn ($x) => $x === 'freebusy'));
    }

    /**
     * Las filas de la última lista de horarios que recibió un teléfono.
     *
     * `$desde` acota la búsqueda a los mensajes posteriores a un punto del
     * recorrido. Sin eso, un test que espera **una lista nueva** encuentra la
     * vieja —WhatsApp no borra nada y el arreglo grabado tampoco— y pasa sin que
     * el bot haya relistado.
     */
    private function filasDeLaUltimaLista(string $telefono, int $desde = 0): array
    {
        $mensajes = array_slice($this->mensajesA($telefono), $desde);

        foreach (array_reverse($mensajes) as $m) {
            if (($m['interactive']['type'] ?? null) === 'list') {
                return $m['interactive']['action']['sections'][0]['rows'];
            }
        }

        return [];
    }

    /** Los instantes ofrecidos en la última lista, sin las filas de paginación. */
    private function horariosOfrecidosA(string $telefono, int $desde = 0): array
    {
        $horarios = [];

        foreach ($this->filasDeLaUltimaLista($telefono, $desde) as $fila) {
            $horario = $this->horarioDe($fila);

            if ($horario !== null) {
                $horarios[] = $horario->getTimestamp();
            }
        }

        return $horarios;
    }

    private function horarioDe(array $fila): ?CarbonImmutable
    {
        $sello = IdSellado::leer($fila['id']);

        return $sello !== null ? ListaDeHorarios::horarioDe($sello->accion) : null;
    }

    private function botonReservar(string $telefono): string
    {
        foreach (array_reverse($this->mensajesA($telefono)) as $m) {
            foreach ($m['interactive']['action']['buttons'] ?? [] as $boton) {
                if (str_starts_with($boton['reply']['id'] ?? '', 'reservar')) {
                    return $boton['reply']['id'];
                }
            }
        }

        $this->fail("Nunca salio el boton «Reservar» para {$telefono}.");
    }

    /** Saluda y toca «Reservar»: queda esperando el nombre. */
    private function hastaElNombre(string $telefono): void
    {
        $this->correr($this->texto('Hola', $telefono));
        $this->correr($this->toca($this->botonReservar($telefono), $telefono));
    }

    /**
     * Recorre el flujo hasta tener la lista en pantalla.
     *
     * @return string  El `id` de la primera fila con horario.
     */
    private function hastaLaLista(string $telefono, string $nombre): string
    {
        $this->hastaElNombre($telefono);
        $this->correr($this->texto($nombre, $telefono));

        foreach ($this->filasDeLaUltimaLista($telefono) as $fila) {
            if ($this->horarioDe($fila) !== null) {
                return $fila['id'];
            }
        }

        $this->fail("Nunca se ofrecio una lista de horarios a {$telefono}.");
    }

    private function conversacionDe(string $telefono, ?Tenant $tenant = null): Conversation
    {
        $tenant ??= $this->tenant;

        return TenantContext::runAs($tenant->id, fn () => Conversation::where('user_phone', $telefono)->firstOrFail());
    }

    private function configDelTenant(?Tenant $tenant = null): BusinessSetting
    {
        $tenant ??= $this->tenant;

        return BusinessSetting::withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    }

    /**
     * El aviso de que el horario se perdió.
     *
     * ⚠️ **Ningún documento fija este texto.** Lo único que el criterio exige es
     * que el cliente se entere de que ese horario ya no está, y que ese aviso
     * **no se confunda** ni con el mensaje de cortesía de RNF-03 —que dice "algo
     * se rompió"— ni con el de agenda llena —que dice "no tengo lugar"—. Las tres
     * situaciones son distintas y un cliente que recibe la equivocada saca la
     * conclusión equivocada. Se afirma esa distinción, no una redacción.
     */
    private function assertAvisoDeColision(string $telefono, int $desde): void
    {
        $config = $this->configDelTenant();
        $posteriores = array_slice($this->mensajesA($telefono), $desde);
        $cuerpos = array_map(fn ($m) => $this->cuerpoDe($m), $posteriores);

        $aviso = null;

        foreach ($cuerpos as $cuerpo) {
            if (mb_stripos($cuerpo, 'disponible') !== false) {
                $aviso = $cuerpo;
            }
        }

        $this->assertNotNull($aviso,
            "A {$telefono} nunca se le avisó que el horario ya no estaba. Recibió: ".json_encode($cuerpos, JSON_UNESCAPED_UNICODE));

        $this->assertNotSame($config->fallback_message, $aviso,
            'El choque de horario se comunicó como una falla del sistema.');

        $this->assertNotSame($config->no_availability_message, $aviso,
            'El choque de horario se comunicó como agenda llena, que es otra cosa.');
    }

    // =====================================================================
    // AC-18.1 · El horario se ocupó entre el listado y la selección
    // =====================================================================

    /**
     * AC-18.1 · Se avisa y se muestra la lista actualizada, **sin volver a
     * pedir los datos**.
     *
     * La segunda respuesta de `freeBusy` declara ocupado justo el horario que el
     * cliente eligió, y por eso no puede fabricarse antes de conocerlo: se
     * resuelve dentro del closure, contra una variable que se completa después
     * de que la lista está en pantalla. La guarda de abajo afirma que esa
     * segunda respuesta **se consumió**: sin re-consulta no se consume, y el
     * test estaría pasando contra la respuesta feliz.
     */
    public function test_si_el_horario_se_ocupo_entre_el_listado_y_la_seleccion_avisa_y_relista(): void
    {
        $ocupado = null;

        $this->fakes(ocupados: function () use (&$ocupado) {
            return $ocupado !== null ? [$ocupado] : [];
        });

        $idHorario = $this->hastaLaLista(self::TEL_ANA, 'María');
        $elegido = $this->horarioDe(['id' => $idHorario]);

        $antes = count($this->mensajesA(self::TEL_ANA));
        $llamadasPrevias = $this->llamadasAFreeBusy();

        // Alguien más lo tomó mientras Ana leía la lista.
        $ocupado = $elegido;

        $this->correr($this->toca($idHorario, self::TEL_ANA));
        $this->sinErroresAnidados();

        $this->assertGreaterThan($llamadasPrevias, $this->llamadasAFreeBusy(),
            'No se volvió a consultar freeBusy: el doble del conflicto nunca se usó.');

        $this->assertSame([], $this->eventosCreados(),
            'Se creó un evento sobre un horario que Google ya daba por ocupado.');

        $this->assertSame(0, DB::table('bookings')->count(),
            'Quedó un turno agendado sobre un horario ocupado.');

        $this->assertAvisoDeColision(self::TEL_ANA, $antes);

        // La lista actualizada llegó **después** del aviso, no es la de antes.
        $this->assertNotEmpty($this->filasDeLaUltimaLista(self::TEL_ANA, $antes),
            'No se mostró la lista actualizada.');

        $this->assertNotContains($elegido->getTimestamp(), $this->horariosOfrecidosA(self::TEL_ANA, $antes),
            'La lista actualizada volvió a ofrecer el horario que se acababa de perder.');

        // AC-18.1 · "sin volver a pedir los datos": el nombre sigue capturado
        // y no se lo volvió a preguntar.
        $this->assertSame('María', $this->conversacionDe(self::TEL_ANA)->context_data['nombre'] ?? null,
            'Se perdió el nombre que el cliente ya había dado.');

        foreach (array_slice($this->mensajesA(self::TEL_ANA), $antes) as $m) {
            $this->assertStringNotContainsString('nombre', $this->cuerpoDe($m),
                'Se le volvió a pedir el nombre después del choque de horario.');
        }
    }

    /**
     * AC-18.1 · La mitad que importa: después del aviso el cliente **termina
     * reservando** eligiendo otro horario, sin repetir nada.
     *
     * Sin esto, "muestra la lista actualizada" podría cumplirse con una lista
     * que el cliente no puede usar —botones caducos, estado que ya no acepta
     * la selección— y el criterio quedaría verde con el flujo roto.
     */
    public function test_tras_el_aviso_el_cliente_reserva_otro_horario_sin_repetir_el_nombre(): void
    {
        $ocupado = null;

        $this->fakes(ocupados: function () use (&$ocupado) {
            return $ocupado !== null ? [$ocupado] : [];
        });

        $idHorario = $this->hastaLaLista(self::TEL_ANA, 'María');
        $ocupado = $this->horarioDe(['id' => $idHorario]);

        $antes = count($this->mensajesA(self::TEL_ANA));

        $this->correr($this->toca($idHorario, self::TEL_ANA));
        $this->sinErroresAnidados();

        // Elige el primer horario de la lista actualizada. Se busca **entre los
        // mensajes posteriores al choque**: la lista vieja sigue grabada y
        // tomarla haría pasar el test sin que el bot hubiera relistado.
        $nuevo = null;

        foreach ($this->filasDeLaUltimaLista(self::TEL_ANA, $antes) as $fila) {
            $horario = $this->horarioDe($fila);

            if ($horario !== null && $horario->getTimestamp() !== $ocupado->getTimestamp()) {
                $nuevo = $fila;
                break;
            }
        }

        $this->assertNotNull($nuevo, 'La lista actualizada no traía ningún horario elegible.');

        $this->correr($this->toca($nuevo['id'], self::TEL_ANA));
        $this->sinErroresAnidados();

        $booking = TenantContext::runAs($this->tenant->id, fn () => Booking::first());

        $this->assertNotNull($booking, 'Después del choque el cliente no pudo reservar otro horario.');
        $this->assertSame('María', $booking->client_name,
            'El turno se creó sin el nombre que el cliente ya había dado.');
        $this->assertSame(
            $this->horarioDe($nuevo)->utc()->format('Y-m-d H:i:s'),
            DB::table('bookings')->value('start_time'),
            'Se agendó un horario distinto del que el cliente eligió en la lista actualizada.'
        );
    }

    /**
     * Decisión § 5 del 2026-08-21 · El cliente al que **se le adelantaron** lee
     * el texto neutro que fijó Diego: *«Ese horario ya no está disponible.
     * Estos sí:»*.
     *
     * Hasta esta decisión ningún documento fijaba la redacción y solo estaba
     * garantizado que no se confundiera con los otros dos avisos. Ahora sí está
     * fijada, y el motivo es de producto, no de estilo: el bot **no suena
     * coloquial** y el mensaje cuenta lo que pasó **sin dar a entender que el
     * sistema falló**. El texto anterior empezaba con *«Uy, justo alguien
     * tomó…»*, que hace las dos cosas al revés.
     *
     * ## Por qué se afirman frases y no el string entero
     *
     * Comparar carácter por carácter pondría el test en rojo por una coma, un
     * espacio o un cambio tipográfico que no cambian nada de lo que el cliente
     * entiende — y el rojo dejaría de significar "se rompió el producto".
     * Afirmar solo *"disponible"* sería peor: **el texto viejo también lo
     * contiene**, así que el test pasaría sin que la decisión estuviera
     * aplicada.
     *
     * Se eligen entonces las dos frases que cargan el sentido de la decisión, y
     * que son justo las que el texto viejo no tiene:
     *
     * - **«ese horario ya no está disponible»** — enuncia el hecho, en neutro y
     *   sin culpable. El viejo intercalaba *«justo alguien tomó»* en el medio.
     * - **«estos sí»** — redirige a la lista nueva afirmando que hay lugar. El
     *   viejo decía *«los que quedan»*, que suena a sobra.
     *
     * Las dos tienen que venir en **el mismo mensaje**: repartidas entre dos, el
     * cliente no lee la frase que se decidió. Qué mensaje sea —el texto suelto o
     * el cuerpo de la lista— queda libre a propósito: la decisión fija lo que se
     * lee, no cómo se empaqueta.
     */
    public function test_al_que_perdio_el_horario_se_le_avisa_con_el_texto_neutro_decidido(): void
    {
        $ocupado = null;

        $this->fakes(ocupados: function () use (&$ocupado) {
            return $ocupado !== null ? [$ocupado] : [];
        });

        $idHorario = $this->hastaLaLista(self::TEL_ANA, 'María');

        $antes = count($this->mensajesA(self::TEL_ANA));

        // Alguien más lo tomó mientras Ana leía la lista.
        $ocupado = $this->horarioDe(['id' => $idHorario]);

        $this->correr($this->toca($idHorario, self::TEL_ANA));
        $this->sinErroresAnidados();

        $cuerpos = array_map(
            fn ($m) => $this->cuerpoDe($m),
            array_slice($this->mensajesA(self::TEL_ANA), $antes)
        );

        $conElHecho = array_values(array_filter(
            $cuerpos,
            fn (string $c) => mb_stripos($c, 'ese horario ya no está disponible') !== false
        ));

        $this->assertNotEmpty($conElHecho,
            'El aviso no enuncia el hecho en neutro: al cliente que perdió el horario se le está '
            .'contando otra cosa, o se le está insinuando que el sistema falló. Recibió: '
            .json_encode($cuerpos, JSON_UNESCAPED_UNICODE));

        $completo = array_values(array_filter(
            $conElHecho,
            fn (string $c) => mb_stripos($c, 'estos sí') !== false
        ));

        $this->assertNotEmpty($completo,
            'El aviso enuncia el hecho pero no redirige a la lista con «Estos sí»: el cliente lee '
            .'que perdió el horario sin leer que igual hay lugar, y se va a buscar turno a otro '
            .'lado. Recibió: '.json_encode($conElHecho, JSON_UNESCAPED_UNICODE));
    }

    // =====================================================================
    // AC-18.2 · Dos clientes, el mismo horario, segundos de diferencia
    // =====================================================================

    /**
     * AC-18.2 · Solo uno lo obtiene y **nunca se crean dos eventos superpuestos**.
     *
     * `freeBusy` contesta *libre* siempre, a propósito: en la ventana real de
     * este choque el evento del primero **todavía no existe**, así que preguntar
     * de nuevo no alcanza para verlo. Lo único que puede evitar el turno doble
     * es que el horario quede apartado al elegirlo. Con el doble de Google
     * diciendo "libre" las dos veces, un test verde solo puede explicarse por el
     * apartado.
     *
     * Bruno toca el mismo ítem **con el `POST` de Ana en vuelo**: Ana ya eligió
     * y todavía no tiene turno. Es la ventana exacta que el ticket describe.
     */
    public function test_dos_clientes_sobre_el_mismo_horario_solo_uno_lo_obtiene(): void
    {
        $idBruno = null;
        $bruno = false;

        $this->fakes(alCrearEvento: function (int $llamada) use (&$idBruno, &$bruno) {
            if ($llamada === 1 && $idBruno !== null) {
                $bruno = true;
                $this->correr($this->toca($idBruno, self::TEL_BRUNO));
            }

            return null;
        });

        // Los dos miran la misma lista: el doble de Google es el mismo para ambos.
        $idAna = $this->hastaLaLista(self::TEL_ANA, 'Ana');
        $idBruno = $this->hastaLaLista(self::TEL_BRUNO, 'Bruno');

        $this->assertSame(
            $this->horarioDe(['id' => $idAna])->getTimestamp(),
            $this->horarioDe(['id' => $idBruno])->getTimestamp(),
            'El escenario no montó a los dos clientes sobre el mismo horario.'
        );

        $antesBruno = count($this->mensajesA(self::TEL_BRUNO));

        $this->correr($this->toca($idAna, self::TEL_ANA));
        $this->sinErroresAnidados();

        $this->assertTrue($bruno, 'Bruno nunca tocó el ítem: el escenario de concurrencia no se ejecutó.');

        // El resultado observable: un evento, un turno.
        $this->assertCount(1, $this->eventosCreados(),
            'Se crearon dos eventos superpuestos en Google sobre el mismo horario.');

        $this->assertSame(1, DB::table('bookings')->count(),
            'Quedaron dos turnos sobre el mismo horario.');

        // Y cada uno se enteró de lo suyo.
        $confirmaciones = array_filter(
            $this->mensajesA(self::TEL_ANA),
            fn ($m) => str_contains($this->cuerpoDe($m), 'quedó reservado')
        );

        $this->assertNotEmpty($confirmaciones, 'El cliente que ganó el horario no recibió confirmación.');

        $this->assertAvisoDeColision(self::TEL_BRUNO, $antesBruno);

        foreach (array_slice($this->mensajesA(self::TEL_BRUNO), $antesBruno) as $m) {
            $this->assertStringNotContainsString('quedó reservado', $this->cuerpoDe($m),
                'Se le confirmó un turno al cliente que no obtuvo el horario.');
        }
    }

    /**
     * El mismo control, pero entre PyMEs: el apartado de una **no puede** dejar
     * sin horario al cliente de la otra.
     *
     * Dos negocios distintos con la misma grilla comparten el instante, no la
     * agenda. Una llave de apartado sin el `tenant_id` adentro —o comparado como
     * entero, que sobre un UUID devuelve `1` para todos— haría que el primer
     * cliente del día bloquee las 09:00 de todas las peluquerías del sistema.
     *
     * ⚠️ **Este test nació verde y es correcto que así sea.** Hoy no hay apartado
     * que pueda cruzarse, así que no puede haber rojo: es una **guarda contra la
     * regresión que la implementación puede introducir**, no un rojo del ciclo.
     * Si se pone rojo, la llave del apartado perdió el tenant.
     */
    public function test_el_apartado_de_un_tenant_no_bloquea_el_horario_de_otro(): void
    {
        $otra = $this->pyme('Consultorio Norte', 'consultorio', self::PHONE_ID_B);

        $idCarla = null;
        $carla = false;

        $this->fakes(alCrearEvento: function (int $llamada) use (&$idCarla, &$carla) {
            if ($llamada === 1 && $idCarla !== null) {
                $carla = true;
                $this->correr($this->toca($idCarla, self::TEL_CARLA, self::PHONE_ID_B));
            }

            return null;
        });

        $idAna = $this->hastaLaLista(self::TEL_ANA, 'Ana');

        // Carla es clienta de la otra PyME: todo su recorrido entra por el otro
        // `phone_number_id`.
        $this->correr($this->textoDe('Hola', self::TEL_CARLA, self::PHONE_ID_B));
        $this->correr($this->toca($this->botonReservar(self::TEL_CARLA), self::TEL_CARLA, self::PHONE_ID_B));
        $this->correr($this->textoDe('Carla', self::TEL_CARLA, self::PHONE_ID_B));

        foreach ($this->filasDeLaUltimaLista(self::TEL_CARLA) as $fila) {
            if ($this->horarioDe($fila) !== null) {
                $idCarla = $fila['id'];
                break;
            }
        }

        $this->assertNotNull($idCarla, 'La otra PyME nunca ofreció horarios.');
        $this->assertSame(
            $this->horarioDe(['id' => $idAna])->getTimestamp(),
            $this->horarioDe(['id' => $idCarla])->getTimestamp(),
            'El escenario no montó a las dos clientas sobre el mismo instante.'
        );

        $this->correr($this->toca($idAna, self::TEL_ANA));
        $this->sinErroresAnidados();

        $this->assertTrue($carla, 'La clienta de la otra PyME nunca tocó el ítem.');

        // Las dos tienen su turno: son agendas distintas.
        $this->assertCount(2, $this->eventosCreados(),
            'Una de las dos PyMEs se quedó sin su turno: el apartado se cruzó entre tenants.');

        $turnos = Booking::withoutTenantScope()->get();
        $this->assertCount(2, $turnos);

        // El `tenant_id` es UUID: comparado como entero, `(int)` devuelve 1 para
        // todos y la fuga pasaría desapercibida.
        $tenants = $turnos->map(fn (Booking $b) => (string) $b->tenant_id)->sort()->values()->all();

        $esperados = collect([$this->tenant->id, $otra->id])->map(fn ($id) => (string) $id)->sort()->values()->all();

        $this->assertSame($esperados, $tenants, 'Los dos turnos no quedaron uno en cada PyME.');
    }

    // =====================================================================
    // AC-18.3 · La reserva temporal y su vencimiento
    // =====================================================================

    /**
     * AC-18.3, primera mitad · Mientras un cliente lo tiene apartado, el horario
     * **no se le ofrece** a otro.
     *
     * Bruno llega hasta el paso del nombre y recién da su nombre —lo que dispara
     * su lista— con el `POST` de Ana en vuelo. `freeBusy` contesta *libre*
     * siempre: el evento de Ana todavía no existe, así que si el horario
     * desaparece de la lista de Bruno solo puede ser por el apartado.
     */
    public function test_un_horario_apartado_por_otro_cliente_no_se_ofrece(): void
    {
        $horariosDeBruno = null;

        $this->fakes(alCrearEvento: function (int $llamada) use (&$horariosDeBruno) {
            if ($llamada === 1) {
                $this->correr($this->texto('Bruno', self::TEL_BRUNO));
                $horariosDeBruno = $this->horariosOfrecidosA(self::TEL_BRUNO);
            }

            return null;
        });

        $idAna = $this->hastaLaLista(self::TEL_ANA, 'Ana');
        $elegido = $this->horarioDe(['id' => $idAna]);

        $this->hastaElNombre(self::TEL_BRUNO);

        $this->correr($this->toca($idAna, self::TEL_ANA));
        $this->sinErroresAnidados();

        $this->assertNotNull($horariosDeBruno, 'Bruno nunca recibió su lista dentro de la ventana.');
        $this->assertNotEmpty($horariosDeBruno, 'La lista de Bruno salió vacía: el escenario no prueba nada.');

        $this->assertNotContains($elegido->getTimestamp(), $horariosDeBruno,
            'Se le ofreció a otro cliente un horario que ya estaba apartado.');
    }

    /**
     * AC-18.3, segunda mitad · **Al vencer el apartado, el horario vuelve a
     * ofrecerse.**
     *
     * Es el mismo escenario que el test de arriba y **solo cambia el reloj**:
     * mientras el `POST` de Ana está en vuelo pasa más tiempo que el TTL —una
     * llamada a Google que se cuelga es exactamente eso—. Que el único cambio
     * sea `setTestNow()` es lo que hace que el vencimiento sea la causa y no una
     * coincidencia.
     *
     * ⚠️ El TTL se lee de la constante, no se escribe el número: la decisión dice
     * que puede moverse de 5 a 10 y este test tiene que seguir valiendo.
     */
    public function test_al_vencer_la_reserva_temporal_el_horario_vuelve_a_ofrecerse(): void
    {
        $horariosDeBruno = null;

        $this->fakes(alCrearEvento: function (int $llamada) use (&$horariosDeBruno) {
            if ($llamada === 1) {
                // Google tarda más que el apartado en contestar.
                CarbonImmutable::setTestNow(
                    CarbonImmutable::now()->addMinutes(\App\Conversacion\ReservaTemporal::MINUTOS_TTL + 1)
                );

                $this->correr($this->texto('Bruno', self::TEL_BRUNO));
                $horariosDeBruno = $this->horariosOfrecidosA(self::TEL_BRUNO);
            }

            return null;
        });

        $idAna = $this->hastaLaLista(self::TEL_ANA, 'Ana');
        $elegido = $this->horarioDe(['id' => $idAna]);

        $this->hastaElNombre(self::TEL_BRUNO);

        $this->correr($this->toca($idAna, self::TEL_ANA));
        $this->sinErroresAnidados();

        $this->assertNotNull($horariosDeBruno, 'Bruno nunca recibió su lista dentro de la ventana.');

        $this->assertContains($elegido->getTimestamp(), $horariosDeBruno,
            'El horario siguió apartado después de vencer la reserva temporal.');
    }

    /**
     * De la decisión del 2026-08-20 · **Un cliente aparta un solo horario a la
     * vez.** Si elige otro, el anterior se libera.
     *
     * Sin esto, alguien que curiosea toca cuatro horarios y bloquea media agenda
     * durante cinco minutos.
     *
     * El recorrido: Ana elige X y Google rechaza el evento, así que se queda sin
     * turno; vuelve a empezar y elige Y. Con el `POST` de Y en vuelo, Bruno pide
     * su lista: **Y no tiene que estar** (Ana lo tiene apartado ahora) y **X sí**
     * (dejó de tenerlo apartado al elegir otro).
     */
    public function test_un_cliente_aparta_un_solo_horario_a_la_vez(): void
    {
        $horariosDeBruno = null;

        $this->fakes(alCrearEvento: function (int $llamada) use (&$horariosDeBruno) {
            // El primer intento de Ana se cae: elige X y se queda sin turno.
            if ($llamada === 1) {
                return Http::response(['error' => ['message' => 'backendError']], 500);
            }

            if ($llamada === 2) {
                $this->correr($this->texto('Bruno', self::TEL_BRUNO));
                $horariosDeBruno = $this->horariosOfrecidosA(self::TEL_BRUNO);
            }

            return null;
        });

        $idX = $this->hastaLaLista(self::TEL_ANA, 'Ana');
        $x = $this->horarioDe(['id' => $idX]);

        $this->correr($this->toca($idX, self::TEL_ANA));
        $this->sinErroresAnidados();

        $this->assertSame(0, DB::table('bookings')->count(),
            'El escenario partió mal: el primer intento tenía que quedarse sin turno.');

        /*
         * Ana vuelve a empezar. La lista se busca **entre los mensajes
         * posteriores al reinicio**: la de antes del choque sigue grabada, y sus
         * botones ya están caducos por el sello de T-019.
         */
        $antes = count($this->mensajesA(self::TEL_ANA));

        $this->correr($this->texto('Hola', self::TEL_ANA));
        $this->correr($this->toca($this->botonReservar(self::TEL_ANA), self::TEL_ANA));
        $this->correr($this->texto('Ana', self::TEL_ANA));

        $idY = null;

        foreach ($this->filasDeLaUltimaLista(self::TEL_ANA, $antes) as $fila) {
            $horario = $this->horarioDe($fila);

            if ($horario !== null && $horario->getTimestamp() !== $x->getTimestamp()) {
                $idY = $fila;
                break;
            }
        }

        $this->assertNotNull($idY, 'No hubo un segundo horario distinto para elegir.');
        $y = $this->horarioDe($idY);

        $this->hastaElNombre(self::TEL_BRUNO);

        $this->correr($this->toca($idY['id'], self::TEL_ANA));
        $this->sinErroresAnidados();

        $this->assertNotNull($horariosDeBruno, 'Bruno nunca recibió su lista dentro de la ventana.');

        $this->assertNotContains($y->getTimestamp(), $horariosDeBruno,
            'El horario que el cliente acaba de elegir no quedó apartado.');

        $this->assertContains($x->getTimestamp(), $horariosDeBruno,
            'El primer horario siguió apartado después de que el cliente eligiera otro.');
    }

    // =====================================================================
    // Doble toque del mismo cliente
    // =====================================================================

    /**
     * Un doble toque sobre el mismo ítem no genera dos eventos.
     *
     * El toque repetido entra **con el primero a mitad de camino**: sale de otro
     * `wamid`, así que la idempotencia de T-011 no lo cubre, y ocurre antes de
     * que el turno exista. Es el dedo nervioso sobre un chat que no responde.
     *
     * ⚠️ **Este test pasó la primera vez que se corrió: el criterio ya estaba
     * cumplido, por el sello de T-019.** `agendar()` aplica `SlotElegido` antes
     * de hablar con Google, así que cuando el `POST` está en vuelo la
     * conversación ya está en `SLOT_SELECTED` con la versión subida y el segundo
     * toque llega caduco. **No es un rojo del ciclo**, es la constatación de que
     * esta parte del ticket no necesita código nuevo. Queda escrito porque hoy
     * nada lo afirmaba, y porque el apartado que T-029 va a agregar pasa por
     * exactamente el mismo camino.
     */
    public function test_un_doble_toque_del_mismo_cliente_no_crea_dos_eventos(): void
    {
        $idHorario = null;
        $repitio = false;

        $this->fakes(alCrearEvento: function (int $llamada) use (&$idHorario, &$repitio) {
            if ($llamada === 1 && $idHorario !== null) {
                $repitio = true;
                $this->correr($this->toca($idHorario, self::TEL_ANA));
            }

            return null;
        });

        $idHorario = $this->hastaLaLista(self::TEL_ANA, 'Ana');

        $this->correr($this->toca($idHorario, self::TEL_ANA));
        $this->sinErroresAnidados();

        $this->assertTrue($repitio, 'El segundo toque nunca se ejecutó.');

        $this->assertCount(1, $this->eventosCreados(),
            'Un doble toque creó dos eventos en Google.');

        $this->assertSame(1, DB::table('bookings')->count(),
            'Un doble toque dejó dos turnos.');
    }

    // =====================================================================
    // Del alcance · Re-consulta inmediatamente antes de crear el evento
    // =====================================================================

    /**
     * Del alcance del ticket · **`freeBusy` se vuelve a preguntar justo antes de
     * crear el evento**, no solo al armar la lista.
     *
     * Se afirma sobre el orden real de las llamadas a terceros y no sobre una
     * llamada a un método: lo que el ticket promete es que entre la selección y
     * la escritura en Google no queda ninguna ventana sin verificar.
     */
    public function test_vuelve_a_preguntarle_a_google_antes_de_crear_el_evento(): void
    {
        $this->fakes();

        $idHorario = $this->hastaLaLista(self::TEL_ANA, 'Ana');

        $this->correr($this->toca($idHorario, self::TEL_ANA));
        $this->sinErroresAnidados();

        $secuencia = $this->secuenciaDeLlamadas();
        $terceros = array_values(array_filter($secuencia, fn ($x) => $x !== 'meta'));

        $this->assertContains('evento', $terceros, 'Nunca se creó el evento: el escenario no llegó al final.');

        $posicionEvento = array_search('evento', $terceros, true);

        $this->assertGreaterThan(0, $posicionEvento,
            'El evento se creó sin ninguna consulta previa de disponibilidad.');

        $this->assertSame('freebusy', $terceros[$posicionEvento - 1],
            'La última llamada antes de crear el evento no fue una re-consulta de freeBusy: '
            .'entre elegir y escribir en Google quedó una ventana sin verificar. Secuencia: '
            .implode(' → ', $terceros));

        $this->assertGreaterThanOrEqual(2, $this->llamadasAFreeBusy(),
            'Solo se consultó freeBusy al listar: no hubo re-consulta al confirmar.');
    }
}
