<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T-036 · Registro de asistencia en `bookings`.
 */
class BookingAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurno(array $atributos = []): int
    {
        $tenant = Tenant::create([
            'name' => 'PyME', 'slug' => 'piloto-'.uniqid(),
            'status' => 'active', 'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $integration = Integration::create([
            'tenant_id' => $tenant->id,
            'provider' => Integration::PROVIDER_GOOGLE_CALENDAR,
            'account_identifier' => 'cal-'.uniqid(),
            'status' => 'connected',
        ]);

        return DB::table('bookings')->insertGetId(array_merge([
            'tenant_id' => $tenant->id,
            'integration_id' => $integration->id,
            'external_event_id' => 'evt-'.uniqid(),
            'client_name' => 'Cliente',
            'client_phone' => '5491133334444',
            'service_name' => 'corte',
            'start_time' => now(),
            'end_time' => now()->addMinutes(30),
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ], $atributos));
    }

    /** La asistencia vive en columna propia, no como valor de `status`. */
    public function test_la_asistencia_es_una_columna_separada_de_status(): void
    {
        $this->assertTrue(Schema::hasColumn('bookings', 'attendance'));

        // `status` no puede haber ganado valores de asistencia.
        $tipo = DB::selectOne("SHOW COLUMNS FROM bookings WHERE Field = 'status'")->Type;

        $this->assertStringNotContainsString('attended', $tipo);
        $this->assertStringNotContainsString('no_show', $tipo);
    }

    /**
     * El caso que justifica todo el ticket: `confirmed` + `no_show` a la vez.
     *
     * Es el dato que sostiene AC-17.4 — sin poder cruzar "confirmó" contra "no
     * vino", se ve que el ausentismo bajó pero no se le puede atribuir causa.
     */
    public function test_un_turno_puede_estar_confirmado_y_ausente_a_la_vez(): void
    {
        $id = $this->crearTurno(['status' => 'confirmed']);

        DB::table('bookings')->where('id', $id)->update([
            'attendance' => 'no_show',
            'attendance_marked_at' => now(),
        ]);

        $turno = DB::table('bookings')->find($id);

        $this->assertSame('confirmed', $turno->status, 'Marcar la ausencia piso el estado de la reserva.');
        $this->assertSame('no_show', $turno->attendance);
    }

    /** Los turnos existentes quedan en `pending`. */
    public function test_un_turno_nuevo_nace_en_pending(): void
    {
        $id = $this->crearTurno();

        $this->assertSame('pending', DB::table('bookings')->find($id)->attendance);
    }

    /** La columna no admite valores fuera del enum. */
    public function test_la_asistencia_no_admite_valores_arbitrarios(): void
    {
        $id = $this->crearTurno();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('bookings')->where('id', $id)->update(['attendance' => 'quiza']);
    }

    /** Auditoria: quien marco y cuando. */
    public function test_registra_quien_marco_la_asistencia_y_cuando(): void
    {
        $this->assertTrue(Schema::hasColumn('bookings', 'attendance_marked_by'));
        $this->assertTrue(Schema::hasColumn('bookings', 'attendance_marked_at'));

        $id = $this->crearTurno();
        DB::table('bookings')->where('id', $id)->update([
            'attendance' => 'attended',
            'attendance_marked_at' => now(),
        ]);

        $this->assertNotNull(DB::table('bookings')->find($id)->attendance_marked_at);
    }
}
