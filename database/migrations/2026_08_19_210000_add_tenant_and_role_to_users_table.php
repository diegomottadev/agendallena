<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-046 · `users` pasa a ser multi-tenant.
 *
 * La tabla era la de Laravel sin tocar: sin `tenant_id` y sin `role`, aunque
 * `04-modelo-de-datos.md` los documenta desde el principio. **Esto no estaba en
 * el alcance de 3 puntos de T-046**, que asume la tabla ya documentada.
 *
 * ## Dos decisiones que el documento del modelo dejaba abiertas
 *
 * **1. El `id` sigue siendo `BIGINT`, no UUID.** El modelo lo documenta como
 * `CHAR(36)` "para evitar la enumeracion de recursos". No se cambia acá porque
 * `personal_access_tokens.tokenable_id` y `bookings.attendance_marked_by` ya
 * apuntan a esta PK: convertirla es una migracion en cadena con ventana de
 * indisponibilidad, no una linea. **El argumento del modelo sigue en pie** — un
 * `id` secuencial permite contar usuarios de la plataforma probando URLs — pero
 * se mitiga con la autorizacion por tenant, que devuelve 403 igual. ⚠️ Si se
 * decide unificar, es antes de tener usuarios reales, no despues.
 *
 * **2. El unico de `email` pasa a ser `(tenant_id, email)`** — H-14. Con el
 * unico global, una misma persona no puede tener cuenta en dos PyMEs: rompe al
 * gestor que administra dos negocios y al profesional que trabaja en dos
 * locales. Con pilotos no se nota; despues es una migracion con datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable a proposito: la tabla puede tener filas y no hay tenant
            // al que asignarlas. La relacion se declara igual.
            $table->uuid('tenant_id')->nullable()->after('id');

            /*
             * `staff` por defecto: el rol mas restrictivo. Si una alta futura se
             * olvida de fijarlo, el error es "no puede configurar" —visible y
             * corregible— y no "puede todo", que no se nota hasta que alguien
             * borra la configuracion del negocio.
             */
            $table->enum('role', ['owner', 'admin', 'staff'])->default('staff')->after('password');

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete()->cascadeOnUpdate();

            $table->index(['tenant_id', 'role']);
        });

        // H-14: el unico de email pasa a ser por tenant.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'role']);
            $table->dropColumn(['tenant_id', 'role']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
