<?php

namespace App\Console\Commands;

use App\Conversacion\Estado;
use App\Conversacion\MaquinaDeEstados;
use App\Conversacion\Pausa;
use App\Conversacion\Transicion;
use App\Models\Conversation;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * T-018b · Vuelve a `IDLE` las conversaciones abandonadas.
 *
 * **Treinta minutos** (decisión cerrada en T-007). Una conversación de turno
 * callada media hora terminó: si el cliente vuelve al día siguiente y escribe
 * "hola", tiene que recibir la bienvenida, no una lista de horarios de ayer que
 * ya no existen.
 *
 * Corre desde el scheduler; ver `routes/console.php`.
 */
class ExpirarConversacionesInactivas extends Command
{
    protected $signature = 'conversaciones:expirar
                            {--minutos= : Minutos de inactividad (por defecto, los 30 de T-007)}
                            {--dry-run : Muestra qué expiraría sin tocar nada}';

    protected $description = 'Devuelve a IDLE las conversaciones inactivas';

    /** Decisión T-007. */
    public const MINUTOS_POR_DEFECTO = 30;

    public function handle(MaquinaDeEstados $maquina): int
    {
        $minutos = (int) ($this->option('minutos') ?: self::MINUTOS_POR_DEFECTO);
        $corte = now()->subMinutes($minutos);
        $simulacion = (bool) $this->option('dry-run');

        /*
         * Solo los estados **en curso**: expirar un `BOOKED` borraría el rastro
         * de un turno real, y un `IDLE` ya está donde tiene que estar. La tabla
         * de transiciones rechaza esos casos igual, pero filtrarlos acá evita
         * recorrer filas para descartarlas después.
         */
        $enCurso = array_map(
            fn (Estado $e) => $e->value,
            array_filter(Estado::cases(), fn (Estado $e) => $e->estaEnCurso()),
        );

        /*
         * Consulta cross-tenant deliberada: es una tarea de plataforma y no
         * corre dentro de la sesión de ningún tenant. Por eso `withoutTenantScope`
         * — el Global Scope exigiría un tenant activo y no hay ninguno (RNF-01).
         */
        $candidatas = Conversation::withoutTenantScope()
            ->whereIn('current_state', $enCurso)
            ->where('last_interaction_at', '<', $corte)
            ->get();

        $expiradas = 0;
        $pausadas = 0;

        foreach ($candidatas as $conversacion) {
            /*
             * **Una conversación pausada no expira.** El operador está
             * atendiéndola a mano: mandarla a `IDLE` le borraría el contexto por
             * abajo mientras conversa, que es lo contrario de lo que la pausa
             * viene a proteger (AC-08.2).
             */
            if (Pausa::estaPausada($conversacion)) {
                $pausadas++;

                continue;
            }

            if ($simulacion) {
                $this->line("  expiraría #{$conversacion->id} ({$conversacion->current_state})");
                $expiradas++;

                continue;
            }

            TenantContext::runAs($conversacion->tenant_id, function () use ($maquina, $conversacion, &$expiradas) {
                try {
                    /*
                     * Se limpia el contexto: los datos de un flujo abandonado
                     * —el horario que estaba eligiendo, el servicio— no valen
                     * para la conversación siguiente, y arrastrarlos haría que
                     * el bot retome con supuestos viejos.
                     */
                    $maquina->aplicar($conversacion, Transicion::Inactividad, limpiarContexto: true);
                    $expiradas++;
                } catch (\Throwable $e) {
                    // Una conversación que no se pudo expirar no puede frenar al
                    // resto: la próxima corrida la vuelve a intentar.
                    Log::warning('No se pudo expirar una conversación', [
                        'tenant_id' => $conversacion->tenant_id,
                        'conversation_id' => $conversacion->id,
                        'codigo' => 'EXPIRACION_FALLIDA',
                        'excepcion' => $e::class,
                    ]);
                }
            });
        }

        $this->info("Conversaciones expiradas: {$expiradas}".($pausadas > 0 ? " · pausadas (no se tocan): {$pausadas}" : ''));

        return self::SUCCESS;
    }
}
