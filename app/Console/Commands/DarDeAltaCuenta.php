<?php

namespace App\Console\Commands;

use App\Meta\AltaDeCuenta;
use App\Meta\EstadoDePlantillasEnMeta;
use App\Meta\PlantillasDelTenant;
use App\Meta\PlantillasDeWhatsApp;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * T-050 · El paso de alta de una PyME nueva, invocable.
 *
 * `AltaDeCuenta` ya sabía crear las tres plantillas en la cuenta del tenant,
 * pero **en producción no la llamaba nadie**: el `waba_id` de un cliente nuevo
 * solo se podía cargar editando a mano un JSON encriptado, y hasta que alguien
 * lo hiciera `EnviarRecordatorios::tieneCuentaDeWhatsApp()` le frenaba **todos**
 * los recordatorios. El síntoma en producción es «a este cliente no le llega
 * nada», sin ningún error que lo avise.
 *
 * ## La cuenta sale de la integración del tenant, nunca de la config
 *
 * ⚠️ `config('services.meta.waba_id')` es una variable de entorno global: usarla
 * acá crearía las plantillas del cliente nuevo en la cuenta del cliente viejo, y
 * es la misma forma exacta del bug del emisor que se arregló el 2026-08-20. Con
 * un solo piloto no se nota.
 *
 * ## Qué pasa al correrlo dos veces · decisión de este ciclo
 *
 * **Es idempotente por plantilla, y reintenta las rechazadas.** Una plantilla ya
 * registrada como `PENDING` o `APPROVED` existe en la cuenta de Meta: volver a
 * pedirla devuelve un error de nombre duplicado que quedaría registrado como
 * `REJECTED`, o sea que **una segunda corrida inocente dejaría al cliente sin
 * poder mandar nada**. Una registrada como `REJECTED` no llegó a crearse, así
 * que reintentarla es justo lo que busca el operador que vuelve a correr el
 * comando después de arreglar el motivo del rechazo.
 *
 * ## El alta es también un «sincronizar estado»
 *
 * Antes de decidir qué crear, le pregunta a Meta en qué estado están las
 * plantillas de esta PyME. Sin eso, una registrada `PENDING` no podía llegar
 * nunca a `APPROVED` —no hay handler del webhook
 * `message_template_status_update`—, el comando informaba «ya tiene sus tres
 * plantillas creadas» y devolvía éxito, y la PyME no mandaba un solo
 * recordatorio. Ahora el operador tiene una palanca; la que corre sola es
 * `plantillas:sincronizar`.
 *
 * ⚠️ Fuera de alcance: el *embedded signup* de Meta y actualizar o borrar
 * plantillas de un cliente que ya está andando.
 */
class DarDeAltaCuenta extends Command
{
    protected $signature = 'cuenta:dar-de-alta
        {tenant : UUID del tenant al que se le da de alta la cuenta de WhatsApp}
        {--waba-id= : WABA ID de la PyME; si no se pasa, se usa el ya cargado}';

    protected $description = 'Carga el waba_id de una PyME y le crea sus tres plantillas en su propia cuenta de WhatsApp';

    public function __construct(private readonly EstadoDePlantillasEnMeta $estadoEnMeta)
    {
        parent::__construct();
    }

    public function handle(AltaDeCuenta $alta): int
    {
        $recibido = trim((string) $this->argument('tenant'));

        $tenant = Tenant::query()->find($recibido);

        /*
         * Se resuelve antes de tocar Meta. El otro camino —seguir con un `null`—
         * revienta más adelante con un error de base o de propiedad sobre `null`
         * que no menciona ni al comando ni al dato que estaba mal.
         */
        if ($tenant === null) {
            $this->error("No existe ningún tenant con id {$recibido}.");

            return self::FAILURE;
        }

        // Un comando no hereda el tenant: se aplica antes de la primera query
        // con scope (RNF-01).
        return TenantContext::runAs(
            (string) $tenant->getKey(),
            fn (): int => $this->darDeAlta($tenant, $alta),
        );
    }

    private function darDeAlta(Tenant $tenant, AltaDeCuenta $alta): int
    {
        $meta = Integration::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('provider', Integration::PROVIDER_META_WHATSAPP)
            ->first();

        if ($meta === null) {
            $this->error("El tenant {$tenant->getKey()} no tiene integración de Meta cargada.");

            return self::FAILURE;
        }

        $wabaId = trim((string) $this->option('waba-id'));

        // Se guarda **antes** de crear nada: si el alta falla a mitad de camino,
        // el dato con el que se reintenta ya quedó en la base.
        if ($wabaId !== '') {
            $this->guardarWabaId($meta, $wabaId);
        }

        if (! AltaDeCuenta::tieneCuenta($meta)) {
            $this->error("El tenant {$tenant->getKey()} no tiene `waba_id` cargado.");
            $this->line('Pasá --waba-id con la cuenta de WhatsApp de esta PyME.');

            return self::FAILURE;
        }

        /*
         * Se le pregunta a Meta el estado actual **antes** de decidir qué hacer.
         * Sin esto, una plantilla registrada `PENDING` no puede llegar nunca a
         * `APPROVED` —no existe handler del webhook
         * `message_template_status_update`—, el comando informaba «ya tiene sus
         * tres plantillas creadas» y devolvía éxito, y la PyME no mandaba un
         * solo recordatorio. Esta consulta es la única palanca que hoy tiene el
         * operador para destrabarla.
         */
        $this->sincronizar($meta, $tenant);

        $porCrear = $this->plantillasPorCrear($meta);

        if ($porCrear === []) {
            return $this->informarSincronizado($meta, $tenant);
        }

        $estados = $alta->crearPlantillas($meta, $porCrear);

        return $this->informar($estados);
    }

    /**
     * RNF-03 · Si Meta no contesta, se dice y se sigue con lo que hay
     * registrado.
     *
     * No aborta el alta: para la PyME nueva —que no tiene nada registrado— lo
     * que importa es crear las tres, y si Meta está caído esa creación va a
     * fallar y a reportarse por su propio camino. Lo que no puede pasar es que
     * la consulta falle callada y el operador crea que el estado que lee es el
     * que Meta tiene.
     */
    private function sincronizar(Integration $meta, Tenant $tenant): void
    {
        try {
            $this->estadoEnMeta->sincronizar($meta);
        } catch (\Throwable $e) {
            $this->error('No se pudo consultarle a Meta el estado de las plantillas del tenant '
                ."{$tenant->getKey()}: {$e->getMessage()}");
            $this->line('Lo que sigue es el último estado registrado, que puede estar viejo.');

            Log::error('No se pudo sincronizar el estado de las plantillas durante el alta', [
                'tenant_id' => $tenant->getKey(),
                'integracion' => 'meta_whatsapp',
                'codigo' => 'PLANTILLAS_SINCRONIZACION_FALLIDA',
                'excepcion' => $e::class,
            ]);
        }
    }

    /**
     * No hay nada que crear: las tres ya existen en la cuenta de Meta.
     *
     * ⚠️ Acá vivía el fallo silencioso. Informar «ya tiene sus tres plantillas
     * creadas» y devolver éxito es verdad y a la vez manda al operador a buscar
     * el problema a cualquier otro lado, mientras la PyME no puede mandar un
     * solo recordatorio. Ahora el estado real se muestra y **el código de salida
     * dice que el cliente todavía no está listo** (RNF-03).
     *
     * El estado no se toca: un `APPROVED` que Meta no dio hace que el envío
     * muera en `template not found`, y el síntoma aparece recién cuando el turno
     * no se avisó.
     */
    private function informarSincronizado(Integration $meta, Tenant $tenant): int
    {
        if (PlantillasDelTenant::todasAprobadas($meta)) {
            $this->info("El tenant {$tenant->getKey()} tiene sus tres plantillas aprobadas por Meta.");

            return self::SUCCESS;
        }

        foreach (PlantillasDelTenant::estados($meta) as $nombre => $estado) {
            $this->line("{$nombre}: {$estado}");
        }

        $this->error("El tenant {$tenant->getKey()} todavía no puede mandar mensajes: Meta no aprobó "
            .'todas sus plantillas.');
        $this->line('La aprobación es asincrónica y su demora no la controlamos. `plantillas:sincronizar` '
            .'vuelve a consultar sola cada '.SincronizarPlantillas::MINUTOS_ENTRE_CORRIDAS.' minutos.');

        return self::FAILURE;
    }

    /**
     * Las plantillas que hay que pedirle a Meta: las que nunca se registraron y
     * las que quedaron rechazadas.
     *
     * @return array<int,string>
     */
    private function plantillasPorCrear(Integration $meta): array
    {
        $estados = PlantillasDelTenant::estados($meta);

        $porCrear = [];

        foreach (PlantillasDeWhatsApp::NOMBRES as $nombre) {
            $estado = strtoupper((string) ($estados[$nombre] ?? ''));

            if ($estado === '' || $estado === AltaDeCuenta::RECHAZADA) {
                $porCrear[] = $nombre;
            }
        }

        return $porCrear;
    }

    /**
     * Deja el `waba_id` en `integrations.settings`, donde ya vive el
     * `verify_token` del tenant. Se relee de la base y se mergea: pisar el JSON
     * entero borraría el `verify_token` y el webhook dejaría de validar.
     */
    private function guardarWabaId(Integration $meta, string $wabaId): void
    {
        $fresco = Integration::query()->find($meta->getKey()) ?? $meta;

        $settings = (array) ($fresco->settings ?? []);
        $settings['waba_id'] = $wabaId;

        $meta->forceFill(['settings' => $settings])->save();
    }

    /**
     * RNF-03 · Ningún camino termina en silencio.
     *
     * Un alta a medias —dos plantillas creadas y una rechazada— es peor que una
     * que falla entera: el cliente parece dado de alta y le faltan mensajes, y
     * el síntoma aparece recién cuando el recordatorio no sale. Por eso la
     * rechazada se nombra y el comando falla.
     *
     * ⚠️ Se informa el nombre y el estado, nunca el `access_token` ni un
     * fragmento suyo.
     *
     * @param  array<string,string>  $estados
     */
    private function informar(array $estados): int
    {
        $rechazadas = [];

        foreach ($estados as $nombre => $estado) {
            if (strtoupper($estado) === AltaDeCuenta::RECHAZADA) {
                $rechazadas[] = $nombre;

                continue;
            }

            $this->info("{$nombre}: {$estado}");
        }

        if ($rechazadas === []) {
            return self::SUCCESS;
        }

        foreach ($rechazadas as $nombre) {
            $this->error("Meta rechazó la plantilla {$nombre}.");
        }

        $this->line('El alta quedó a medias: revisá el log y volvé a correr el comando.');

        return self::FAILURE;
    }
}
