<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-017 · Tercer texto configurable: "no hay disponibilidad".
 *
 * T-014 creó `business_settings` con los dos textos que RNF-03 y RF-A6 nombran
 * —bienvenida y fallback— pero el alcance de T-017 pide **tres**. El que faltaba
 * es el de US-19 / T-024: qué le contesta el bot a alguien que pide turno y no
 * hay ninguno libre.
 *
 * Es un texto distinto del de fallback y confundirlos empeora la experiencia:
 * el fallback dice "algo se rompió, te atiende una persona"; este dice "no
 * tengo lugar ese día". Un cliente que recibe el de error cuando en realidad la
 * agenda está llena piensa que el negocio no funciona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->text('no_availability_message')->nullable()->after('fallback_message');
        });

        // Las filas que ya existen quedan con el texto por defecto: un tenant
        // configurado ayer no puede pasar a responder vacío por esta migración.
        $porDefecto = \App\Models\BusinessSetting::valoresPorDefecto()['no_availability_message'];

        \Illuminate\Support\Facades\DB::table('business_settings')
            ->whereNull('no_availability_message')
            ->update(['no_availability_message' => $porDefecto]);
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn('no_availability_message');
        });
    }
};
