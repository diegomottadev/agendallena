<?php

namespace Tests\Feature;

use App\Jobs\ProcessMessageJob;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-039 · Los estados de mensaje que manda Meta por webhook.
 *
 * Quinto criterio del ticket: *"los estados `sent`, `delivered`, `read` y
 * `failed` se distinguen a partir de los webhooks de Meta"*.
 *
 * ## ⚠️ Hoy no existe ningún manejador de `statuses`
 *
 * Lo verifiqué antes de escribir el archivo: `grep -rn "statuses" app/ tests/` no
 * devuelve nada. `ProcessMessageJob::procesarCambio()` itera `$value['messages']`
 * y **descarta el resto del cambio en silencio**, con este comentario textual:
 *
 * > ⚠️ Los webhooks de estado (`sent`, `delivered`, `read`, `failed`) no se
 * > procesan todavia: son la fuente de T-039 y no tienen `message_id` propio,
 * > asi que su deduplicacion es un problema distinto.
 *
 * O sea que este criterio **no es "mostrar un dato que ya existe"**: es una vía
 * de ingesta nueva. Está señalado en el informe: es alcance mayor del que los 3
 * puntos del ticket sugieren.
 *
 * ## Por qué importa para el negocio
 *
 * `notification_logs.status` queda hoy en `sent` para siempre, y `sent` significa
 * *"Meta aceptó el mensaje"*, no *"al cliente le llegó"*. Un número mal cargado,
 * un teléfono apagado o un bloqueo dan `sent` igual y después `failed` por
 * webhook, minutos más tarde. Sin consumir esos estados, la tasa de ausentismo de
 * T-038 compara "le avisamos" contra "no vino" usando un "le avisamos" que en
 * realidad quiere decir "lo intentamos".
 *
 * ## Contrato que estos tests fijan
 *
 * | Qué | Cómo |
 * | :-- | :-- |
 * | El estado se ata al registro | Por `notification_logs.whatsapp_message_id` = `statuses[].id` |
 * | `sent`/`delivered`/`read` | Se copian tal cual a `notification_logs.status` |
 * | `failed` | Además guarda `failure_reason` con lo que dice Meta |
 * | El tenant | Sale del `phone_number_id`, igual que la ingesta de mensajes |
 *
 * ## Ambigüedades que no afirmo
 *
 * ⚠️ **Meta no garantiza el orden.** Un `delivered` puede llegar después de un
 * `read` y dejar el registro "retrocedido". Si los estados tienen que avanzar en
 * un solo sentido es una decisión que nadie tomó, y fijarla en un test sería
 * inventarla. **No hay test de orden en este archivo.**
 *
 * ⚠️ **La deduplicación de los estados tampoco.** Los `statuses` no traen id
 * propio —lo dice el comentario de `ProcessMessageJob`— así que la tabla
 * `processed_messages` no les sirve. Qué pasa con una reentrega del mismo estado
 * queda sin afirmar.
 *
 * ⚠️ **`type = 'confirmation'` y `'reminder_2h'` no se afirman.** El criterio
 * habla de recordatorios; que el mismo camino sirva para la confirmación de
 * reserva es lo natural, pero no está escrito en ningún lado.
 */
class EstadosDeMensajeDeMetaTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Bogota';

    private const AHORA = '2026-08-22 14:00:00';

    private const PHONE_NUMBER_ID = '1053554814514902';

    private const TELEFONO = '573001112233';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AHORA, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        TenantContext::forget();
        parent::tearDown();
    }

    // ------------------------------------------------------------- montaje

    private function tenant(string $slug, string $phoneNumberId): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Peluquería '.$slug,
            'slug' => $slug.'-'.uniqid(),
            'status' => 'active',
            'timezone' => self::TZ,
        ]);

        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_META_WHATSAPP,
            'account_identifier' => $phoneNumberId,
            'access_token' => 'meta',
            'settings' => ['verify_token' => 'tok', 'waba_id' => 'waba-'.$phoneNumberId],
            'status' => 'connected',
        ]);

        Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'duenio+'.$phoneNumberId.'@peluqueria.com',
            'access_token' => 'ya29',
            'refresh_token' => '1//r',
            'status' => 'connected',
        ]);

        return $tenant;
    }

    /** Un turno con su recordatorio ya aceptado por Meta: el estado llega después. */
    private function recordatorioAceptado(Tenant $tenant, string $wamid): int
    {
        $booking = TenantContext::runAs($tenant->id, function () use ($tenant) {
            $conversacion = Conversation::create([
                'tenant_id' => $tenant->id,
                'user_phone' => self::TELEFONO,
                'current_state' => 'BOOKED',
                'state_version' => 4,
                'context_data' => ['nombre' => 'Ana', 'servicio' => 'Corte de pelo'],
                'last_interaction_at' => now(),
            ]);

            $google = Integration::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
                ->first();

            $inicio = CarbonImmutable::parse('2026-08-23 15:00:00', 'UTC');

            return Booking::create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversacion->id,
                'integration_id' => $google->id,
                'external_event_id' => 'evt_google_'.uniqid(),
                'client_name' => 'Ana',
                'client_phone' => self::TELEFONO,
                'service_name' => 'Corte de pelo',
                'start_time' => $inicio,
                'end_time' => $inicio->addMinutes(30),
                'status' => Booking::ESTADO_AGENDADO,
                'attendance' => Booking::ASISTENCIA_PENDIENTE,
            ]);
        });

        return DB::table('notification_logs')->insertGetId([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'type' => NotificationLog::TIPO_RECORDATORIO_24H,
            'whatsapp_message_id' => $wamid,
            // `sent` es lo que deja T-037: Meta **aceptó** el mensaje. Lo que
            // sigue —si llegó, si lo leyeron, si rebotó— llega por webhook.
            'status' => NotificationLog::ESTADO_ENVIADO,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * El webhook de estado tal como lo manda Meta.
     *
     * ⚠️ **No tiene `messages`.** Es el detalle que hace que hoy el cambio entero
     * se descarte: `procesarCambio()` solo mira esa clave.
     *
     * @param  array<int,array<string,mixed>>  $statuses
     * @return array<string,mixed>
     */
    private function payload(array $statuses, string $phoneNumberId = self::PHONE_NUMBER_ID): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '1360872979217448',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15551547290',
                            'phone_number_id' => $phoneNumberId,
                        ],
                        'statuses' => $statuses,
                    ],
                ]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function estado(string $wamid, string $estado, ?array $errores = null): array
    {
        $status = [
            'id' => $wamid,
            'status' => $estado,
            'timestamp' => '1755620000',
            'recipient_id' => self::TELEFONO,
            'conversation' => ['id' => 'conv-'.uniqid()],
        ];

        if ($errores !== null) {
            $status['errors'] = $errores;
        }

        return $status;
    }

    /** @return array<string,mixed> */
    private function fila(int $id): array
    {
        $fila = DB::table('notification_logs')->where('id', $id)->first();

        return $fila === null ? [] : (array) $fila;
    }

    // -------------------------------------------------------------- tests

    /**
     * **El `delivered` de Meta es el primer momento en que sabemos que llegó.**
     *
     * Por qué importa: `sent` solo dice que Meta aceptó el mensaje. Hasta que no
     * llega el `delivered`, un turno "recordado" puede ser un turno cuyo
     * recordatorio se quedó en el camino — y la PyME pagó la plantilla igual.
     */
    public function test_el_webhook_de_meta_marca_el_recordatorio_como_entregado(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);
        $registro = $this->recordatorioAceptado($tenant, 'wamid.RECORDATORIO_1');

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.RECORDATORIO_1', 'delivered'),
        ])))->handle();

        $this->assertSame('delivered', $this->fila($registro)['status'] ?? null,
            'Meta avisó que el recordatorio se entregó y el registro sigue en `sent`: '
            .'no se puede distinguir el que llegó del que solo se intentó.');
    }

    /**
     * **El `read` es la señal más fuerte de que el cliente vio el turno.**
     *
     * Por qué importa: es el dato que separa *"le llegó y no contestó"* de *"ni
     * siquiera lo abrió"*. Con el ausentismo calculado sobre los turnos marcados
     * (T-038), es lo único que permite decir si el recordatorio no sirve o si no
     * se leyó.
     */
    public function test_el_webhook_de_meta_marca_el_recordatorio_como_leido(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);
        $registro = $this->recordatorioAceptado($tenant, 'wamid.RECORDATORIO_1');

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.RECORDATORIO_1', 'read'),
        ])))->handle();

        $this->assertSame('read', $this->fila($registro)['status'] ?? null,
            'El cliente leyó el recordatorio y el registro no lo refleja.');
    }

    /**
     * **Un `failed` por webhook es un recordatorio que no llegó, y llega tarde.**
     *
     * Por qué importa: es el caso que el envío sincrónico **no puede ver**. Meta
     * contesta `200` y el rebote llega minutos después por webhook. Sin esto, el
     * turno figura recordado para siempre y nadie llama al cliente.
     *
     * El motivo que manda Meta es lo que hace la diferencia entre *"el número no
     * tiene WhatsApp"* —que el dueño arregla— y *"la ventana se cerró"*, que no.
     */
    public function test_un_estado_failed_de_meta_deja_el_registro_fallido_con_el_motivo(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);
        $registro = $this->recordatorioAceptado($tenant, 'wamid.RECORDATORIO_1');

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.RECORDATORIO_1', 'failed', errores: [[
                'code' => 131_026,
                'title' => 'Message undeliverable',
                'message' => 'Message could not be delivered',
            ]]),
        ])))->handle();

        $fila = $this->fila($registro);

        $this->assertSame(NotificationLog::ESTADO_FALLIDO, $fila['status'] ?? null,
            'Meta avisó que el mensaje rebotó y el registro sigue diciendo que salió bien.');

        $this->assertNotEmpty(trim((string) ($fila['failure_reason'] ?? '')),
            'El rebote no dejó registrado el motivo que mandó Meta: sin él nadie sabe si es un número mal cargado.');

        $this->assertStringContainsString('undeliverable',
            mb_strtolower((string) ($fila['failure_reason'] ?? '')),
            'El motivo guardado no es el que mandó Meta.');

        $this->assertNotNull($fila['failed_at'] ?? null,
            'El rebote no dejó la hora del fallo.');
    }

    /**
     * **El `sent` por webhook confirma lo que ya sabíamos, y no rompe nada.**
     *
     * Por qué importa: Meta manda los cuatro estados y el primero es `sent`. Si
     * el manejador solo contempla tres, el cuarto va a caer en algún `else` que
     * nadie pensó. El criterio pide distinguir los cuatro, así que el cuarto
     * también se afirma.
     *
     * El montaje arranca el registro en `queued` a propósito —es el estado que
     * deja `tomarElCandado()` antes de llamar a Meta— para que el `sent` del
     * webhook tenga algo que cambiar y el test no pase por casualidad.
     */
    public function test_el_webhook_de_meta_marca_el_recordatorio_como_enviado(): void
    {
        $tenant = $this->tenant('piloto', self::PHONE_NUMBER_ID);
        $registro = $this->recordatorioAceptado($tenant, 'wamid.RECORDATORIO_1');

        DB::table('notification_logs')->where('id', $registro)
            ->update(['status' => NotificationLog::ESTADO_ENCOLADO, 'sent_at' => null]);

        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.RECORDATORIO_1', 'sent'),
        ])))->handle();

        $this->assertSame(NotificationLog::ESTADO_ENVIADO, $this->fila($registro)['status'] ?? null,
            'El `sent` del webhook no mueve el registro: los cuatro estados de Meta no se distinguen.');
    }

    /**
     * **RNF-01 · Un estado que llega por el número de una PyME no toca el
     * registro de otra.**
     *
     * Por qué importa: el `wamid` es la única llave con la que se resuelve el
     * registro, y es global de Meta, no nuestro. Si la búsqueda no filtra por
     * tenant, un payload con un `wamid` ajeno —mal ruteado por Meta, o mandado a
     * propósito por alguien con la firma de su propia cuenta— escribe sobre el
     * registro de otro negocio.
     *
     * ⚠️ **Con canario en el mismo test.** "El registro ajeno no se tocó" es
     * cierto hoy en vacío, porque nada procesa `statuses`. Por eso el mismo
     * payload trae **también** el `wamid` propio del tenant que lo manda: la
     * primera aserción falla mientras el manejador no exista, y recién cuando
     * exista la segunda empieza a probar el aislamiento.
     *
     * ⚠️ La comparación de tenant tiene que ser **como string**: `tenant_id` es
     * UUID y un `(int)` sobre un UUID devuelve `1` para todos.
     */
    public function test_un_estado_de_meta_no_toca_el_registro_de_otra_pyme(): void
    {
        $mia = $this->tenant('mia', '1111111111');
        $ajena = $this->tenant('ajena', '2222222222');

        $propio = $this->recordatorioAceptado($mia, 'wamid.PROPIO');
        $delOtro = $this->recordatorioAceptado($ajena, 'wamid.AJENO');

        // El payload entra por el número de "mia" y nombra los dos wamid.
        (new ProcessMessageJob($this->payload([
            $this->estado('wamid.PROPIO', 'delivered'),
            $this->estado('wamid.AJENO', 'delivered'),
        ], phoneNumberId: '1111111111')))->handle();

        $this->assertSame('delivered', $this->fila($propio)['status'] ?? null,
            'Canario: el estado del propio recordatorio tampoco se procesó, así que este test no probaría el aislamiento.');

        $this->assertSame(NotificationLog::ESTADO_ENVIADO, $this->fila($delOtro)['status'] ?? null,
            'Un webhook que entró por el número de una PyME modificó el registro de otra.');
    }
}
