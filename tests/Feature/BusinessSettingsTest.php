<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-014 · Migracion `business_settings` y aislamiento por tenant.
 */
class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function crearTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => 'PyME '.$slug,
            'slug' => $slug,
            'status' => 'active',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
    }

    /** Un tenant recien creado tiene configuracion utilizable sin intervencion. */
    public function test_un_tenant_nuevo_nace_con_configuracion_por_defecto(): void
    {
        $tenant = $this->crearTenant('peluqueria-sur');

        $config = BusinessSetting::withoutTenantScope()
            ->where('tenant_id', $tenant->id)->first();

        $this->assertNotNull($config, 'El tenant nacio sin configuracion.');
        $this->assertSame(30, $config->slot_duration_minutes);
        $this->assertSame(10, $config->buffer_minutes);
        $this->assertNotEmpty($config->welcome_message);
        $this->assertNotEmpty($config->fallback_message);

        // Tiene que poder atender de entrada: sin horario no ofrece un solo turno.
        $this->assertTrue($config->abreEl('mon'));
        $this->assertFalse($config->abreEl('sun'));
    }

    /** El JSON soporta jornada partida, que es la razon de haber elegido JSON. */
    public function test_el_horario_admite_jornada_partida(): void
    {
        $tenant = $this->crearTenant('peluqueria-centro');

        TenantContext::runAs($tenant->id, function () {
            $config = BusinessSetting::first();
            $config->business_hours = array_merge($config->business_hours, [
                'mon' => [['09:00', '13:00'], ['16:00', '20:00']],
            ]);
            $config->save();
        });

        $config = BusinessSetting::withoutTenantScope()->where('tenant_id', $tenant->id)->first();

        $this->assertCount(2, $config->rangosDe('mon'));
        $this->assertSame(['16:00', '20:00'], $config->rangosDe('mon')[1]);
    }

    /** RNF-01: el Global Scope filtra por el tenant activo. */
    public function test_el_global_scope_aisla_por_tenant(): void
    {
        $a = $this->crearTenant('tenant-a');
        $b = $this->crearTenant('tenant-b');

        $this->assertSame(2, BusinessSetting::withoutTenantScope()->count());

        TenantContext::runAs($a->id, function () use ($a) {
            $this->assertSame(1, BusinessSetting::count());
            $this->assertSame($a->id, BusinessSetting::first()->tenant_id);
        });

        TenantContext::runAs($b->id, function () use ($b) {
            $this->assertSame($b->id, BusinessSetting::first()->tenant_id);
        });
    }

    /**
     * Sin tenant activo, consultar **lanza** en vez de devolver todo.
     *
     * Es la mitad que importa del diseño: un scope que no filtra cuando falta el
     * contexto devuelve las filas de todas las PyMEs y nadie se entera.
     */
    public function test_sin_tenant_activo_la_consulta_falla_ruidoso(): void
    {
        $this->crearTenant('tenant-a');
        TenantContext::forget();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No hay tenant activo/');

        BusinessSetting::count();
    }

    /** El tenant_id se completa solo desde el contexto al crear. */
    public function test_el_tenant_id_se_completa_desde_el_contexto(): void
    {
        $tenant = $this->crearTenant('tenant-a');
        DB::table('business_settings')->delete();

        TenantContext::runAs($tenant->id, function () {
            // A proposito sin pasar tenant_id.
            BusinessSetting::create(BusinessSetting::valoresPorDefecto());
        });

        $fila = DB::table('business_settings')->first();
        $this->assertSame($tenant->id, $fila->tenant_id);
    }

    /** El contexto se restaura aunque el callback lance. */
    public function test_run_as_restaura_el_contexto_ante_una_excepcion(): void
    {
        $a = $this->crearTenant('tenant-a');
        $b = $this->crearTenant('tenant-b');

        TenantContext::set($a->id);

        try {
            TenantContext::runAs($b->id, function () {
                throw new \RuntimeException('algo falla');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame($a->id, TenantContext::get(),
            'Quedo activo el tenant equivocado: las queries siguientes leerian otra PyME.');
    }

    /** Borrar el tenant se lleva su configuracion. */
    public function test_borrar_el_tenant_borra_su_configuracion(): void
    {
        $tenant = $this->crearTenant('tenant-a');

        $this->assertSame(1, BusinessSetting::withoutTenantScope()->count());

        $tenant->delete();

        $this->assertSame(0, BusinessSetting::withoutTenantScope()->count());
    }

    /** Una sola configuracion por tenant. */
    public function test_no_se_pueden_crear_dos_configuraciones_para_un_tenant(): void
    {
        $tenant = $this->crearTenant('tenant-a');

        $this->expectException(\Illuminate\Database\QueryException::class);

        BusinessSetting::withoutTenantScope()->create(
            ['tenant_id' => $tenant->id] + BusinessSetting::valoresPorDefecto()
        );
    }
}
