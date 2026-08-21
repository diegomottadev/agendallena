<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * T-014 · Configuracion operativa del negocio, una fila por tenant.
 *
 * Los horarios son **locales al tenant**, no UTC: son reglas de negocio
 * ("abrimos a las 9"), no instantes. La conversion la hace quien consulta
 * disponibilidad, con `tenants.timezone` (RNF-02).
 */
class BusinessSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'business_hours',
        'slot_duration_minutes',
        'buffer_minutes',
        'search_window_days',
        'welcome_message',
        'fallback_message',
        'no_availability_message',
        'human_phone',
    ];

    protected function casts(): array
    {
        return [
            'business_hours' => 'array',
            'slot_duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'search_window_days' => 'integer',
        ];
    }

    /**
     * Configuracion utilizable sin que el tenant toque nada.
     *
     * Un tenant recien creado tiene que poder atender: si el horario llegara
     * vacio, el motor de disponibilidad no ofreceria un solo turno y el sintoma
     * seria "el bot no anda", no "falta configurar".
     *
     * Lunes a viernes de 9 a 18, sabados de 9 a 13, domingos cerrado.
     *
     * ⚠️ El texto de cortesía sale textual de RNF-03. Los valores de horario son
     * una propuesta nuestra: **RF-E3 sigue en borrador** y ningun requisito
     * acordado fija cual es el horario por defecto.
     *
     * @return array<string,mixed>
     */
    public static function valoresPorDefecto(): array
    {
        $lunesAViernes = [['09:00', '18:00']];

        return [
            'business_hours' => [
                'mon' => $lunesAViernes,
                'tue' => $lunesAViernes,
                'wed' => $lunesAViernes,
                'thu' => $lunesAViernes,
                'fri' => $lunesAViernes,
                'sat' => [['09:00', '13:00']],
                'sun' => [],
            ],
            'slot_duration_minutes' => 30,
            'buffer_minutes' => 10,
            // T-024: 7 dias para que 'no tengo lugar esta semana' signifique algo.
            'search_window_days' => 7,
            'welcome_message' => '¡Hola! Gracias por escribirnos. ¿En qué te podemos ayudar?',
            // Textual de RNF-03.
            'fallback_message' => 'En este momento nuestro sistema de turnos está actualizándose, '
                .'un asesor te atenderá a la brevedad.',
            /*
             * Distinto del fallback a propósito: este dice "no tengo lugar",
             * aquel dice "algo se rompió". Un cliente que recibe el de error
             * cuando la agenda simplemente está llena piensa que el negocio no
             * funciona.
             */
            'no_availability_message' => 'Por ahora no tengo turnos disponibles para ese día. '
                .'¿Querés que busque en otra fecha?',
        ];
    }

    /**
     * ¿El negocio abre ese dia?
     *
     * @param  string  $dia  `mon`, `tue`, ...
     */
    public function abreEl(string $dia): bool
    {
        return ! empty($this->business_hours[$dia] ?? []);
    }

    /**
     * Rangos de atencion de un dia, en hora local del tenant.
     *
     * @return array<int,array{0:string,1:string}>
     */
    public function rangosDe(string $dia): array
    {
        return $this->business_hours[$dia] ?? [];
    }
}
