<?php

namespace App\Console\Commands;

use App\Meta\AltaDeCuenta;
use App\Meta\EstadoDePlantillasEnMeta;
use App\Meta\PlantillasDelTenant;
use App\Models\Integration;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * T-050 · **La PyME trabada en `PENDING` se destraba sola.**
 *
 * `cuenta:dar-de-alta` también sincroniza, pero eso le da al operador una
 * palanca a ciegas: **nadie le avisa cuándo Meta aprobó**, porque no existe
 * handler del webhook `message_template_status_update`. Tendría que adivinar
 * cuándo correr el comando, y mientras adivina `EnviarRecordatorios` selecciona
 * con `whereBetween('start_time', [ahora+24h ± tolerancia])`: **el turno que ya
 * cruzó la ventana de t-24h no lo recupera nada**. Cada hora trabada quema
 * turnos contra un KPI de asistencia ≥ 92%.
 *
 * ## Por qué es un comando propio y no `cuenta:dar-de-alta --todos`
 *
 * Los contratos son incompatibles y mezclarlos rompe uno de los dos:
 *
 * 1. **El código de salida.** El alta *tiene que* fallar cuando quedan
 *    plantillas sin aprobar, para que el operador no lea «está todo listo». Una
 *    tarea agendada que devuelve `FAILURE` en cada corrida porque una PyME sigue
 *    esperando a Meta es ruido puro, y el operador deja de mirar el scheduler.
 * 2. **El alta crea (`POST`); esta tarea solo lee.** Una tarea automática con
 *    derecho a crear plantillas en la cuenta de un cliente es un riesgo que
 *    nadie pidió correr: re-pedir una que ya existe devuelve nombre duplicado y
 *    la deja `REJECTED`. El daño sería silencioso **y repetido**.
 *
 * ⚠️ Esto es el parche, no la solución: la solución es el webhook
 * `message_template_status_update`, que es alcance nuevo sin ticket.
 */
class SincronizarPlantillas extends Command
{
    protected $signature = 'plantillas:sincronizar';

    protected $description = 'Le pregunta a Meta el estado de las plantillas de cada PyME y destraba a la que ya fue aprobada';

    /**
     * Cada cuánto corre la sincronización, en minutos.
     *
     * Vive acá y no en `routes/console.php` porque es política, no plomería: es
     * **cuánto puede tardar una PyME en poder mandar después de que Meta ya la
     * aprobó**, y eso se traduce directo en turnos que cruzan la ventana de
     * t-24h sin recordatorio. El scheduler deriva su expresión de cron de este
     * número, así que moverlo es una sola línea.
     *
     * ⚠️ **El valor es decisión del team-lead del 2026-08-21, pendiente de que
     * Diego la ratifique**: ningún documento fija la cadencia. El fundamento es
     * que la tarea **saltea a las PyMEs con las tres ya aprobadas**, así que en
     * régimen no consulta a nadie —`PENDING` solo existe mientras se da de alta
     * un cliente— y una cadencia corta es casi gratis. Es además el mismo número
     * que `ConciliarAgendamientos::MINUTOS_ENTRE_CORRIDAS`, que es uno menos para
     * recordar.
     */
    public const MINUTOS_ENTRE_CORRIDAS = 15;

    public function handle(EstadoDePlantillasEnMeta $estado): int
    {
        $sincronizadas = 0;
        $salteadas = 0;
        $fallidas = 0;

        /*
         * Consulta cross-tenant deliberada, como en `ConciliarAgendamientos`: es
         * una tarea de plataforma y no corre dentro de la sesión de ninguna
         * PyME. El orden es el mismo que usa la conciliación —`created_at`, con
         * el `id` de desempate porque dos altas del mismo segundo empatan— para
         * que la corrida sea reproducible.
         */
        foreach (Tenant::query()->orderBy('created_at')->orderBy('id')->cursor() as $tenant) {
            $meta = Integration::query()
                ->where('tenant_id', $tenant->id)
                ->where('provider', Integration::PROVIDER_META_WHATSAPP)
                ->first();

            /*
             * Sin cuenta de WhatsApp cargada no hay dónde consultar. No es un
             * fallo: es una PyME a la que todavía no le corrieron el alta.
             */
            if ($meta === null || ! AltaDeCuenta::tieneCuenta($meta)) {
                $salteadas++;

                continue;
            }

            /*
             * `APPROVED` es terminal para lo que decide el envío: consultar a la
             * PyME que ya tiene las tres es una llamada por tenant en cada
             * corrida para no enterarse de nada, contra la misma cuota de Meta
             * que usa el camino crítico del cliente.
             */
            if (PlantillasDelTenant::todasAprobadas($meta)) {
                $salteadas++;

                continue;
            }

            // Un comando no hereda el tenant: se aplica antes de tocar nada suyo
            // (RNF-01).
            $ok = TenantContext::runAs(
                (string) $tenant->id,
                fn (): bool => $this->sincronizarTenant($estado, $tenant, $meta),
            );

            $ok ? $sincronizadas++ : $fallidas++;
        }

        $this->info("Plantillas sincronizadas: {$sincronizadas} · PyMEs salteadas: {$salteadas} "
            ."· fallidas: {$fallidas}");

        /*
         * Éxito aunque alguna PyME siga esperando a Meta, y aunque alguna haya
         * fallado: lo que falló ya quedó en el log con su `tenant_id`. Una tarea
         * agendada que devuelve error en cada corrida porque un cliente espera
         * una aprobación que no controlamos deja de mirarse, y entonces el día
         * que falle de verdad tampoco la va a mirar nadie.
         */
        return self::SUCCESS;
    }

    /**
     * Una PyME. **El fallo no sube** (RNF-03).
     *
     * Es el mismo patrón que `ConciliarAgendamientos::conciliarTenant()`: un
     * token vencido o una caída de Meta es un problema de **una** cuenta. Si la
     * excepción cortara la corrida, un solo cliente con la integración rota
     * dejaría a toda la cartera trabada en `PENDING`, y el síntoma —«no le llega
     * nada a nadie»— no menciona ni al tenant ni a Meta.
     */
    private function sincronizarTenant(
        EstadoDePlantillasEnMeta $estado,
        Tenant $tenant,
        Integration $meta,
    ): bool {
        try {
            $estado->sincronizar($meta);

            return true;
        } catch (\Throwable $e) {
            /*
             * RNF-03 · Ningún camino termina en silencio. La PyME que no se pudo
             * sincronizar sigue trabada, y sin esta línea el operador no tiene ni
             * el dato para ir a mirarla. Lleva el `tenant_id` adentro y el código
             * propio; nunca el `access_token` ni un fragmento suyo.
             */
            Log::error('No se pudo sincronizar el estado de las plantillas de la PyME', [
                'tenant_id' => $tenant->id,
                'integracion' => 'meta_whatsapp',
                'codigo' => 'PLANTILLAS_SINCRONIZACION_FALLIDA',
                'excepcion' => $e::class,
            ]);

            return false;
        }
    }
}
