<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * T-015 · Validación de coherencia de la agenda, del lado del servidor.
 *
 * **AC-15.6 no se recorta** aunque el recorte Pareto difiera el horario partido:
 * una configuración incoherente no rompe al guardarla, rompe días después
 * cuando el bot deja de ofrecer turnos y nadie entiende por qué.
 */
class GuardarAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El permiso lo aplica el middleware `rol:configurar` en la ruta.
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'dias' => ['required', 'array', 'min:1'],
            'dias.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],

            // `date_format` y no un regex: acepta 09:00 y rechaza 25:00 o 9:0.
            'apertura' => ['required', 'date_format:H:i'],
            'cierre' => ['required', 'date_format:H:i'],

            'slot_duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:240'],

            // Catálogo IANA real, no una lista escrita a mano que envejece.
            'timezone' => ['required', 'string', 'timezone'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'dias.required' => 'Elegí al menos un día de atención.',
            'dias.min' => 'Elegí al menos un día de atención.',
            'apertura.date_format' => 'La hora de apertura tiene que ser como 09:00.',
            'cierre.date_format' => 'La hora de cierre tiene que ser como 18:00.',
            'timezone.timezone' => 'Esa zona horaria no existe.',
            'slot_duration_minutes.min' => 'Un turno no puede durar menos de 5 minutos.',
        ];
    }

    /**
     * Las reglas que dependen de más de un campo — el corazón de AC-15.6.
     */
    public function after(): array
    {
        return [
            function (Validator $v) {
                $apertura = $this->str('apertura')->toString();
                $cierre = $this->str('cierre')->toString();
                $duracion = (int) $this->integer('slot_duration_minutes');
                $buffer = (int) $this->integer('buffer_minutes');

                // AC-15.6 · Cierre anterior o igual a la apertura.
                if ($apertura !== '' && $cierre !== '' && $cierre <= $apertura) {
                    $v->errors()->add('cierre',
                        "El cierre ({$cierre}) tiene que ser posterior a la apertura ({$apertura}).");
                }

                // AC-15.6 · Buffer mayor que la duración del turno.
                if ($buffer > $duracion) {
                    $v->errors()->add('buffer_minutes',
                        "El descanso entre turnos ({$buffer} min) no puede ser mayor que la duración del turno ({$duracion} min).");
                }

                /*
                 * No está en AC-15.6, pero es el mismo modo de falla: una
                 * configuración que guarda bien y no produce ni un turno. Pasa
                 * con una jornada de 30 minutos y turnos de 45. El síntoma sería
                 * "el bot nunca ofrece horarios", que se diagnostica tardísimo.
                 */
                if ($apertura !== '' && $cierre !== '' && $cierre > $apertura && $duracion > 0) {
                    $minutos = (strtotime($cierre) - strtotime($apertura)) / 60;

                    if ($minutos < $duracion) {
                        $v->errors()->add('cierre',
                            'La jornada dura '.(int) $minutos." minutos y un turno dura {$duracion}: no entraría ningún turno.");
                    }
                }
            },
        ];
    }
}
