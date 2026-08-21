<?php

namespace App\Leads;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Support\HoraLocal;
use Carbon\CarbonInterface;

/**
 * T-045 · La fila que el lead ocupa en la planilla de la PyME.
 *
 * ## El orden de las columnas es el de AC-13.1
 *
 * `fecha, teléfono, nombre, monto cotizado, estado`, y después las dos que
 * agrega AC-13.2 al reservar. Ningún RF lo escribió: sale de leer el criterio en
 * el orden en que está.
 *
 * ⚠️ **El orden no se puede tocar después.** Una PyME que ya está andando tiene
 * la planilla llena de filas con este orden; mover una columna corre todos los
 * datos históricos un lugar. Si hay que agregar algo, va al final.
 *
 * ## Las fechas van en la zona del negocio
 *
 * RNF-02 dice UTC en la base y conversión al mostrar. Una planilla que la dueña
 * abre y lee **es mostrar**: un lead que entró a las 8 de la mañana no puede
 * figurar a las 11.
 */
class FilaDeLead
{
    /**
     * ⚠️ **«Monto cotizado» queda vacío y está bien.** El cotizador está
     * congelado (decisión § 10) y `bookings.quote_amount` es siempre `null` hoy.
     * La columna existe igual porque el día que el cotizador exista, la planilla
     * de las PyMEs que ya están andando no puede cambiar de forma.
     *
     * ⚠️ **«Evento en el calendario» lleva el `id` del evento y no una URL.**
     * `bookings` no persiste el `htmlLink` que devuelve Google al crearlo, y la
     * URL de un evento de Calendar no se puede reconstruir a partir del `id`
     * solo. Fabricar un enlace que no abre nada sería peor que nombrar el dato.
     * Ver el informe: persistir `htmlLink` es el ciclo rojo siguiente.
     *
     * @var array<int,string>
     */
    public const ENCABEZADOS = [
        'Fecha del lead',
        'Teléfono',
        'Nombre',
        'Monto cotizado',
        'Estado',
        'Fecha del turno',
        'Evento en el calendario',
    ];

    /**
     * ⚠️ **Los dos textos del estado los fija este archivo.** Ningún documento
     * dice si un lead sin turno se llama «consulta», «lead» o «pendiente». Lo que
     * sí está definido es que la celda tiene que decir algo: sin ella, la PyME no
     * distingue al que abandonó del que ya reservó, que es la única razón por la
     * que persigue la lista.
     */
    public const ESTADO_CONSULTA = 'Consulta sin turno';

    public const ESTADO_AGENDADO = 'Turno agendado';

    /**
     * @return array<int,string>
     */
    public static function para(Conversation $conversacion, Tenant $tenant, ?Booking $turno): array
    {
        return [
            self::fechaDelLead($conversacion, $tenant),
            (string) $conversacion->user_phone,
            self::nombre($conversacion, $turno),
            // Sin cotizador no hay monto. Un cero acá sería un precio inventado.
            '',
            $turno !== null ? self::ESTADO_AGENDADO : self::ESTADO_CONSULTA,
            $turno !== null ? self::fecha($turno->start_time, $tenant) : '',
            $turno !== null ? (string) $turno->external_event_id : '',
        ];
    }

    /**
     * Cuándo entró el lead, no cuándo se escribió la fila.
     *
     * Son distintos: la escritura ocurre en un job que puede reintentarse horas
     * después si Sheets estuvo caído, y la PyME que ordena por fecha necesita el
     * momento en que la persona escribió.
     */
    private static function fechaDelLead(Conversation $conversacion, Tenant $tenant): string
    {
        return self::fecha($conversacion->created_at ?? now(), $tenant);
    }

    /**
     * `2026-08-24 09:00`, en la zona del negocio.
     *
     * ⚠️ **ISO y no `d/m/Y`**, que es el formato del panel (`HoraLocal::corta`).
     * La planilla no es una pantalla: es una columna que la PyME **ordena y
     * filtra**. En ISO, el orden alfabético y el cronológico coinciden, así que
     * la columna se ordena bien incluso cuando Sheets la toma como texto —que es
     * lo que pasa si la planilla la creó la dueña con la columna en formato
     * texto—. Con `d/m/Y`, ese mismo orden pone marzo antes que enero.
     *
     * La conversión de zona pasa por `HoraLocal` igual que todo lo que se le
     * muestra a un humano (RNF-02); acá solo cambia el formato.
     */
    private static function fecha(CarbonInterface $instante, Tenant $tenant): string
    {
        return HoraLocal::en($instante, $tenant)->format('Y-m-d H:i');
    }

    private static function nombre(Conversation $conversacion, ?Booking $turno): string
    {
        $delContexto = $conversacion->context_data['nombre'] ?? null;

        if (is_string($delContexto) && trim($delContexto) !== '') {
            return trim($delContexto);
        }

        // Al reservar, el nombre ya viajó al turno. Es el mismo dato por otro
        // camino, y sirve de red si el contexto se limpió con «menú».
        return trim((string) ($turno?->client_name ?? $conversacion->user_name ?? ''));
    }
}
