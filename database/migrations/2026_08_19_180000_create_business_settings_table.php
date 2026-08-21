<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-014 · Configuracion operativa del negocio.
 *
 * Cinco historias del MVP leen configuracion que hoy no tiene donde vivir: de
 * los cinco dominios que configura US-15 solo persisten dos, la zona horaria en
 * `tenants.timezone` y las formulas en `quote_rules`. RF-B2 exige "respetando
 * horarios de atencion" y "buffers de 10 minutos", y RNF-03 exige un mensaje de
 * cortesia configurable: los tres consumen algo que ninguna tabla contenia.
 *
 * ## Decision del ticket: horarios en JSON, no en columnas por dia
 *
 * El ticket dejaba abierto cual de las dos. Va JSON por una razon de mercado y
 * no de elegancia: **la jornada partida es la norma en las PyMEs
 * latinoamericanas** — una peluqueria abre 9 a 13 y 16 a 20. Con columnas
 * `apertura`/`cierre` por dia eso no se puede expresar, y duplicarlas a 28
 * columnas sigue poniendo un techo arbitrario en dos rangos.
 *
 * Lo que se resigna es consultar el horario desde SQL, que el ticket marca como
 * relevante para un reporte por franja horaria en v2. Es recuperable: MySQL 8.4
 * indexa rutas JSON con columnas generadas, sin migrar los datos.
 *
 * Forma de `business_hours`: cada dia, una lista de rangos. Lista vacia = cerrado.
 *
 *   {"mon": [["09:00","13:00"], ["16:00","20:00"]], "sun": []}
 *
 * Las horas son **locales al tenant**, no UTC: son reglas de negocio ("abrimos
 * a las 9"), no instantes. La conversion la hace el motor de disponibilidad
 * usando `tenants.timezone` (RNF-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');

            // Horario de atencion y dias laborables. Un dia con lista vacia esta
            // cerrado: no hace falta una columna aparte de dias laborables.
            $table->json('business_hours');

            $table->unsignedSmallInteger('slot_duration_minutes')->default(30);
            // RF-B2 pide 10 minutos de buffer entre turnos.
            $table->unsignedSmallInteger('buffer_minutes')->default(10);

            // RF-A6: primer contacto. RNF-03: cortesia ante caida de un tercero.
            $table->text('welcome_message');
            $table->text('fallback_message');

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            // Una configuracion por tenant. El unico lidera con `tenant_id` por
            // la regla del proyecto y, siendo la unica columna, hace de indice
            // de lectura para el motor de disponibilidad.
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
