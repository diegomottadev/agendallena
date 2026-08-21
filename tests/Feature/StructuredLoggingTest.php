<?php

namespace Tests\Feature;

use App\Logging\AddStructuredContext;
use App\Logging\RedactSecrets;
use App\Support\TenantContext;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * T-020 · Logging estructurado con `tenant_id` y sin secretos.
 */
class StructuredLoggingTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function registro(string $mensaje, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \Monolog\DateTimeImmutable(true),
            channel: 'testing',
            level: Level::Info,
            message: $mensaje,
            context: $context,
        );
    }

    private function redactar(string $mensaje, array $context = []): LogRecord
    {
        return (new RedactSecrets)($this->registro($mensaje, $context));
    }

    private function comoTexto(LogRecord $r): string
    {
        return $r->message.' '.json_encode($r->context).' '.json_encode($r->extra);
    }

    // ---------------------------------------------------------------- tenant

    /** AC: el tenant_id aparece sin que el llamador lo pase. */
    public function test_inyecta_el_tenant_id_sin_que_el_llamador_lo_pase(): void
    {
        TenantContext::set('01a01a7c-1d7d-723b-bc77-0af419b9883c');

        $r = (new AddStructuredContext)($this->registro('algo paso'));

        $this->assertSame('01a01a7c-1d7d-723b-bc77-0af419b9883c', $r->extra['tenant_id']);
    }

    /** Fuera de contexto de tenant vale null, y no rompe: el logger no puede lanzar. */
    public function test_sin_tenant_activo_registra_null_y_no_lanza(): void
    {
        TenantContext::forget();

        $r = (new AddStructuredContext)($this->registro('arranque'));

        $this->assertNull($r->extra['tenant_id']);
    }

    /** Toda entrada tiene las mismas claves, asi la consulta no contempla ausencias. */
    public function test_toda_entrada_lleva_los_campos_fijos(): void
    {
        $r = (new AddStructuredContext)($this->registro('x'));

        foreach (['tenant_id', 'canal', 'correlacion', 'integracion', 'codigo'] as $campo) {
            $this->assertArrayHasKey($campo, $r->extra, "Falta el campo fijo `{$campo}`.");
        }
    }

    /** La correlacion une entradas del mismo request. */
    public function test_la_correlacion_es_estable_dentro_del_mismo_proceso(): void
    {
        $p = new AddStructuredContext;

        $this->assertSame(
            $p($this->registro('uno'))->extra['correlacion'],
            $p($this->registro('dos'))->extra['correlacion']
        );
    }

    // -------------------------------------------------------------- secretos

    /** AC: ningun secreto configurado aparece, ni parcialmente. */
    public function test_redacta_los_secretos_configurados_aunque_esten_en_el_texto(): void
    {
        config()->set('services.meta.app_secret', 'f4533b0aadff4c86455014a08b849b2f');
        config()->set('services.meta.verify_token', 'LtKyC6w_AsvBh1jVdS-YWU2RoRBMQEUd');

        $r = $this->redactar(
            'Fallo con secret f4533b0aadff4c86455014a08b849b2f y token LtKyC6w_AsvBh1jVdS-YWU2RoRBMQEUd'
        );

        $texto = $this->comoTexto($r);
        $this->assertStringNotContainsString('f4533b0aadff4c86455014a08b849b2f', $texto);
        $this->assertStringNotContainsString('LtKyC6w_AsvBh1jVdS-YWU2RoRBMQEUd', $texto);
        $this->assertStringContainsString('[REDACTADO]', $texto);
    }

    /** Redacta por nombre de clave, contenga lo que contenga. */
    public function test_redacta_por_nombre_de_clave(): void
    {
        $r = $this->redactar('intento', [
            'access_token' => 'lo-que-sea',
            'refresh_token' => 'otra-cosa',
            'Authorization' => 'Bearer abc',
            'X-Hub-Signature-256' => 'sha256=deadbeef',
            'telefono' => '5491133334444',
        ]);

        $this->assertSame('[REDACTADO]', $r->context['access_token']);
        $this->assertSame('[REDACTADO]', $r->context['refresh_token']);
        $this->assertSame('[REDACTADO]', $r->context['Authorization']);
        $this->assertSame('[REDACTADO]', $r->context['X-Hub-Signature-256']);
        // Lo que no es secreto se conserva: un log sin datos no sirve.
        $this->assertSame('5491133334444', $r->context['telefono']);
    }

    /**
     * Redacta por forma: un token que NO es el nuestro.
     *
     * Es el caso que las otras dos vias no cubren — el token de otro tenant, o
     * uno rotado hace cinco minutos que ya no esta en la configuracion.
     */
    public function test_redacta_secretos_ajenos_por_su_forma(): void
    {
        config()->set('services.meta.app_secret', 'otro-secreto-distinto');

        // ⚠️ Ficticio a proposito, con la **forma** de un token de Meta y nada
        // mas: el valor que estaba antes tenia la entropia de uno real y este
        // repositorio es publico. Lo que el test necesita es la forma.
        $ajeno = 'EAAfictic1oNOesUNtokenREALniSirveParaNadaSoloTieneLaFormaQue00000';
        $firma = 'sha256='.str_repeat('a', 64);

        $texto = $this->comoTexto($this->redactar("token {$ajeno} firma {$firma}"));

        $this->assertStringNotContainsString($ajeno, $texto);
        $this->assertStringNotContainsString($firma, $texto);
    }

    /** Los secretos anidados tambien se redactan. */
    public function test_redacta_en_estructuras_anidadas(): void
    {
        $r = $this->redactar('x', [
            'integracion' => ['provider' => 'meta_whatsapp', 'settings' => ['verify_token' => 'ultra-secreto']],
        ]);

        $this->assertStringNotContainsString('ultra-secreto', $this->comoTexto($r));
        // El dato util sobrevive.
        $this->assertSame('meta_whatsapp', $r->context['integracion']['provider']);
    }

    /**
     * Una excepcion arrastra su mensaje, que es donde suelen viajar los tokens.
     */
    public function test_redacta_dentro_de_una_excepcion_del_contexto(): void
    {
        config()->set('services.meta.access_token', 'EAAtoken-super-secreto-largo');

        $r = $this->redactar('fallo', [
            'exception' => new \RuntimeException('401 con EAAtoken-super-secreto-largo'),
        ]);

        $this->assertStringNotContainsString('EAAtoken-super-secreto-largo', $this->comoTexto($r));
    }

    /**
     * Un secreto corto no puede disparar reemplazos espurios.
     *
     * Si `app.key` o un token quedaran vacios o de pocos caracteres, buscarlos
     * literalmente destruiria texto legitimo.
     */
    public function test_un_secreto_corto_o_vacio_no_rompe_el_texto(): void
    {
        config()->set('services.meta.app_secret', 'abc');
        config()->set('services.meta.verify_token', '');

        $r = $this->redactar('el turno abc quedo confirmado');

        $this->assertStringContainsString('el turno abc quedo confirmado', $r->message);
    }

    // ----------------------------------------------------------- consultable

    /** AC: contar excepciones por integracion y tenant con una sola consulta. */
    public function test_el_canal_estructurado_emite_json_por_linea(): void
    {
        $config = config('logging.channels.structured');

        $this->assertSame('daily', $config['driver']);
        $this->assertContains(\App\Logging\StructuredChannel::class, $config['tap']);
        $this->assertSame(30, (int) $config['max_files'], 'La retencion documentada es de 30 dias.');
    }
}
