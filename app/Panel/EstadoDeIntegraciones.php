<?php

namespace App\Panel;

use App\Models\Integration;
use App\Models\Tenant;

/**
 * T-034 · Qué integraciones tiene la PyME y cuáles están caídas (US-25).
 *
 * **El dato ya se escribía y nadie había construido dónde leerlo.** T-013 marca
 * `integrations.status = 'expired'` ante un `invalid_grant` de Google, y hasta
 * este ticket esa columna no la miraba ninguna pantalla: una credencial vencida
 * se manifestaba como un producto que dejó de funcionar sin explicación, y la
 * primera señal llegaba por un cliente enojado.
 *
 * ## Por qué siempre las tres, y no las que existan
 *
 * `integrations` solo tiene fila cuando alguien conectó algo. Listando solo lo
 * que existe, *"Sheets nunca se conectó"* y *"Sheets está sano"* se ven
 * idénticos —la pantalla vacía— y en el MVP **todos** los tenants están en el
 * primer caso, porque T-044 y T-045 están diferidos.
 *
 * ⚠️ `sin_configurar` **no es un valor del ENUM de la base y no debe serlo**: es
 * la ausencia de fila traducida para la pantalla.
 *
 * ## Por qué el aviso se deriva y no se guarda
 *
 * AC-25.3 pide que el aviso desaparezca solo cuando la integración vuelve a
 * funcionar. Como se calcula desde `status` en cada request, apagarlo es
 * consecuencia de que T-013 escriba `connected` y de nada más. Un aviso
 * persistido habría que apagarlo a mano, quedaría prendido para siempre y el
 * dueño aprendería a ignorarlo — justo el día que se rompe algo de verdad.
 *
 * ## Por qué el filtro por tenant va explícito
 *
 * `Integration` es la única tabla de negocio **sin** Global Scope: la ingesta
 * resuelve el tenant *a partir* del `phone_number_id`, o sea que consulta antes
 * de saber de quién es. Acá el aislamiento depende de este `where` (RNF-01).
 */
class EstadoDeIntegraciones
{
    /** La integración que nunca se conectó: no hay fila que leer. */
    public const SIN_CONFIGURAR = 'sin_configurar';

    /** El único estado que hoy genera aviso. Lo escribe T-013. */
    public const VENCIDA = 'expired';

    /**
     * Las tres del MVP, en el orden en que se muestran: primero la que rompe el
     * negocio entero, última la que solo alimenta una planilla.
     *
     * ⚠️ `outlook_calendar` está en el ENUM del modelo de datos y **no** acá:
     * quedó OUT-OF-SCOPE del PRD y no hay código detrás.
     *
     * @var array<int,string>
     */
    public const PROVEEDORES = [
        Integration::PROVIDER_GOOGLE_CALENDAR,
        Integration::PROVIDER_META_WHATSAPP,
        Integration::PROVIDER_GOOGLE_SHEETS,
    ];

    /**
     * AC-25.2 · Qué se rompió, qué consecuencia tiene y qué hacer, por integración.
     *
     * Los textos son **distintos por proveedor a propósito**: un aviso genérico
     * —*"hay un problema con una integración"*— no le dice al dueño si dejó de
     * vender turnos o si dejó de llenarse una planilla que mira una vez por
     * semana. Son urgencias distintas.
     *
     * ⚠️ Ningún texto nombra el código técnico (`invalid_grant`, `expired`, el
     * `401`, el `oauth`): el dueño no sabe qué es ninguno de esos, y no tiene por
     * qué. Lo que necesita saber es qué le está costando plata mientras no lo
     * arregle.
     *
     * @var array<string,array<string,string>>
     */
    private const AVISOS = [
        Integration::PROVIDER_GOOGLE_CALENDAR => [
            'nombre' => 'Google Calendar',
            'que_se_rompio' => 'Se cortó la conexión con tu calendario de Google.',
            'consecuencia' => 'El bot no puede ver tus horarios libres ni reservar: '
                .'los clientes que escriban se quedan sin turno.',
        ],
        Integration::PROVIDER_META_WHATSAPP => [
            'nombre' => 'WhatsApp',
            'que_se_rompio' => 'Se cortó la conexión con tu cuenta de WhatsApp.',
            'consecuencia' => 'El bot no puede mandar ni responder mensajes: '
                .'quien te escriba no recibe respuesta, y los recordatorios no salen.',
        ],
        Integration::PROVIDER_GOOGLE_SHEETS => [
            'nombre' => 'Google Sheets',
            'que_se_rompio' => 'Se cortó la conexión con tu planilla de Google.',
            'consecuencia' => 'Los datos dejan de volcarse a la planilla: '
                .'quien preguntó y no reservó no queda anotado en ningún lado.',
        ],
    ];

    /**
     * ⚠️ AC-25.4 · El paso siguiente dice **contactanos, con todas las letras**.
     *
     * La reconexión autoservicio es v2 y está fuera de alcance del ticket. Un
     * aviso que ofrece un botón que no existe es peor que no avisar: el dueño
     * toca, no pasa nada, y deja de confiar en la pantalla.
     */
    private const PASO_SIGUIENTE = 'Contactanos y la reconectamos nosotros: en el MVP la reconexión la hacemos de nuestro lado.';

    /**
     * AC-25.1 · Las tres integraciones, con su estado y con qué cuenta quedó
     * vinculada cada una.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function de(Tenant $tenant): array
    {
        $filas = self::filasDe($tenant);

        $salida = [];

        foreach (self::PROVEEDORES as $provider) {
            $fila = $filas[$provider] ?? null;

            $salida[] = [
                'provider' => $provider,
                'nombre' => self::AVISOS[$provider]['nombre'],
                'status' => $fila?->status ?? self::SIN_CONFIGURAR,
                'account_identifier' => $fila?->account_identifier,
                // El booleano y **nunca el token**: el panel solo necesita saber
                // si la conexión quedó completa.
                'has_refresh_token' => $fila !== null && filled($fila->refresh_token),
            ];
        }

        return $salida;
    }

    /**
     * AC-25.2 · Los avisos de las integraciones caídas. `[]` cuando está todo bien.
     *
     * ⚠️ **Solo `expired`.** El ENUM también tiene `disconnected`, que hoy no
     * escribe nadie, y `sin_configurar`, que es la ausencia de fila. Si una de
     * esas dos tiene que avisar igual es una decisión de producto que nadie tomó;
     * avisar de una integración que nunca se conectó, además, dejaría el aviso
     * prendido para siempre en todos los tenants del MVP —ninguno tiene Sheets—
     * y con eso se aprende a ignorarlo.
     *
     * @return array<int,array<string,string>>
     */
    public static function avisos(Tenant $tenant): array
    {
        $avisos = [];

        foreach (self::de($tenant) as $integracion) {
            if ($integracion['status'] !== self::VENCIDA) {
                continue;
            }

            $texto = self::AVISOS[$integracion['provider']];

            $avisos[] = [
                'provider' => $integracion['provider'],
                'nombre' => $texto['nombre'],
                'que_se_rompio' => $texto['que_se_rompio'],
                'consecuencia' => $texto['consecuencia'],
                'paso_siguiente' => self::PASO_SIGUIENTE,
            ];
        }

        return $avisos;
    }

    /**
     * Las filas del tenant, por proveedor. Una consulta y una sola.
     *
     * @return array<string,Integration>
     */
    private static function filasDe(Tenant $tenant): array
    {
        return Integration::query()
            // RNF-01 · Explícito porque `Integration` no lleva Global Scope: sin
            // este `where`, el panel de una PyME lista las cuentas de todas.
            ->where('tenant_id', $tenant->id)
            ->whereIn('provider', self::PROVEEDORES)
            ->get()
            ->keyBy('provider')
            ->all();
    }
}
