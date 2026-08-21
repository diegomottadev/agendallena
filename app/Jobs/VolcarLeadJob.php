<?php

namespace App\Jobs;

use App\Leads\FilaDeLead;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\LeadSpreadsheet;
use App\Models\Tenant;
use App\Services\Google\PlanillaDeGoogle;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * T-045 · Escribe el lead en la planilla de la PyME, **fuera del chat**.
 *
 * ## Por qué es un job y no una llamada más adentro del mensaje
 *
 * AC-13.3 pide que la conversación *"continúe sin interrupción"* y AC-22.4 que
 * un fallo de Sheets **no impida el turno**. Las dos cosas se cumplen por
 * construcción si la escritura no está en el camino crítico: el cliente ya
 * recibió su confirmación cuando esto arranca.
 *
 * Y hay un motivo de presupuesto: RNF-04 ya está incumplido con las dos llamadas
 * a terceros que hay hoy —2.361 ms de p95 contra 1.500—. Una tercera adentro del
 * mensaje lo empeora sin que nadie gane nada: la planilla puede escribirse un
 * segundo después.
 *
 * ## Este job no lanza nunca
 *
 * Un `throw` acá se convierte en un `throw` del `ProcessMessageJob` cuando la
 * cola corre en modo síncrono, y eso rompería exactamente lo que AC-22.4
 * protege. Los fallos se registran y se devuelven a la cola con `release()`, que
 * es un reintento de verdad —cuenta contra `$tries` y termina en `failed()`— sin
 * arrastrarse hacia arriba.
 */
class VolcarLeadJob implements ShouldQueue
{
    use Queueable;

    /**
     * AC-13.3 · *"se reintenta en segundo plano"*. Cinco intentos con el backoff
     * de abajo cubren una caída de Sheets de un cuarto de hora, que es más de lo
     * que duran sus incidentes habituales.
     */
    public int $tries = 5;

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    /**
     * @param  string  $tenantId  ⚠️ Viaja en el payload: **un job no hereda el
     *   tenant**. Se aplica con `TenantContext::runAs()` antes de la primera
     *   consulta con scope (RNF-01).
     * @param  int|null  $bookingId  El turno que el lead acaba de sacar, si lo
     *   sacó. `null` es el alta del lead de AC-13.1.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly int $conversationId,
        public readonly ?int $bookingId = null,
    ) {}

    public function handle(): void
    {
        try {
            TenantContext::runAs($this->tenantId, fn () => $this->volcar());
        } catch (\Throwable $e) {
            /*
             * RNF-03 · Ningún camino termina en silencio, y este es el que más
             * lo necesita: nadie del otro lado está esperando esta escritura, así
             * que si no queda registrada, no existió para nadie.
             */
            Log::warning('No se pudo volcar el lead a la planilla', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'integracion' => 'google_sheets',
                'codigo' => 'VOLCADO_LEAD_FALLO',
                'excepcion' => $e::class,
            ]);

            $this->release(60);
        }
    }

    /**
     * AC-13.3 · Agotados los reintentos, el lead **no llegó**.
     *
     * No hay nada que decirle al cliente —él ya tiene su turno— pero la PyME
     * tiene una fila que no está en su planilla, y eso solo se puede saber por
     * acá.
     */
    public function failed(?\Throwable $e): void
    {
        Log::error('El lead no llegó a la planilla después de todos los reintentos', [
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'booking_id' => $this->bookingId,
            'integracion' => 'google_sheets',
            'codigo' => 'VOLCADO_LEAD_PERDIDO',
            'excepcion' => $e !== null ? $e::class : null,
        ]);
    }

    private function volcar(): void
    {
        $vinculo = LeadSpreadsheet::query()
            // Filtro explícito además del Global Scope: la planilla de una PyME
            // no puede recibir los leads de otra (RNF-01).
            ->where('tenant_id', $this->tenantId)
            ->first();

        if ($vinculo === null) {
            /*
             * La dueña nunca vinculó una planilla, o la desvinculó entre que este
             * job se encoló y se ejecutó. No es un error: T-044 es una pantalla
             * que se puede no haber tocado nunca.
             */
            return;
        }

        $conversacion = Conversation::query()->find($this->conversationId);
        $tenant = Tenant::query()->find($this->tenantId);

        if ($conversacion === null || $tenant === null) {
            Log::warning('El lead a volcar ya no existe', [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $this->conversationId,
                'codigo' => 'VOLCADO_LEAD_SIN_CONVERSACION',
            ]);

            return;
        }

        $google = Integration::query()
            ->where('tenant_id', $this->tenantId)
            ->where('provider', Integration::PROVIDER_GOOGLE_CALENDAR)
            ->first();

        if ($google === null) {
            // El scope de Sheets viaja en el mismo consentimiento que Calendar:
            // sin esa integración no hay token con el que escribir.
            Log::warning('No hay cuenta de Google con la cual escribir la planilla', [
                'tenant_id' => $this->tenantId,
                'integracion' => 'google_sheets',
                'codigo' => 'VOLCADO_LEAD_SIN_GOOGLE',
            ]);

            return;
        }

        $turno = $this->bookingId !== null
            ? Booking::query()->where('tenant_id', $this->tenantId)->find($this->bookingId)
            : null;

        $celdas = FilaDeLead::para($conversacion, $tenant, $turno);
        $planilla = app(PlanillaDeGoogle::class);

        /*
         * **AC-13.2 · el upsert.** Si la referencia guardada apunta a la planilla
         * que hoy está vinculada, el lead ya tiene su fila y se reescribe esa.
         *
         * Si apunta a otra —la dueña cambió de planilla (AC-26.4)—, **no se
         * actualiza nada**: la fila 2 de la planilla nueva es de otra persona, y
         * pisarla no pierde un lead sino que arruina uno que estaba bien.
         */
        if ($this->tieneFilaEn($conversacion, $vinculo->spreadsheet_id)) {
            $ok = $planilla->actualizarFila(
                $google,
                $vinculo->spreadsheet_id,
                $vinculo->sheet_name,
                (int) $conversacion->lead_sheet_row,
                $celdas,
            );

            if (! $ok) {
                $this->release($this->segundosDeEspera());
            }

            return;
        }

        $fila = $planilla->agregarFila($google, $vinculo->spreadsheet_id, $vinculo->sheet_name, $celdas);

        if ($fila === null) {
            $this->release($this->segundosDeEspera());

            return;
        }

        $this->recordarLaFila($conversacion, $vinculo->spreadsheet_id, $fila);
    }

    private function tieneFilaEn(Conversation $conversacion, string $spreadsheetId): bool
    {
        return $conversacion->lead_sheet_row !== null
            && (string) $conversacion->lead_sheet_id === $spreadsheetId;
    }

    /**
     * Guarda dónde quedó el lead, en columnas propias.
     *
     * `forceFill` sobre dos columnas y nada más: `context_data` y
     * `current_state` los maneja la máquina de estados bajo su lock, y escribir
     * acá cualquiera de los dos pisaría una transición que esté ocurriendo.
     */
    private function recordarLaFila(Conversation $conversacion, string $spreadsheetId, int $fila): void
    {
        $conversacion->forceFill([
            'lead_sheet_id' => $spreadsheetId,
            'lead_sheet_row' => $fila,
        ])->save();
    }

    /**
     * Cuánto esperar antes del reintento, según el intento en curso.
     *
     * `release()` no consulta `backoff()` —eso lo hace el worker cuando el job
     * lanza—, así que la espera se elige acá para que un Sheets caído no reciba
     * cinco intentos en el mismo minuto.
     */
    private function segundosDeEspera(): int
    {
        $esperas = $this->backoff();
        $intento = max(1, $this->attempts());

        return $esperas[$intento - 1] ?? end($esperas);
    }
}
