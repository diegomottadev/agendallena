# US-10 · El recordatorio que baja el ausentismo

| | |
| :-- | :-- |
| **Sección** | 4 — Demostrar el ROI que vendemos |
| **Prioridad** | 10 de 14 |
| **Estimación** | **M** |
| **Trazabilidad** | RF-D1 · CA-05 |
| **Depende de** | [US-06](US-06-reservar-y-liberar-sync-inversa.md) |
| **Bloquea a** | [US-11](US-11-reagendar-y-aviso-t2h.md) · [US-17](US-17-registro-de-asistencia.md) · [US-24](US-24-recordatorio-que-no-salio.md) |

## Historia

> **Como** cliente final con un turno reservado,
> **quiero** recibir un recordatorio el día anterior con opciones para responder,
> **para** confirmar o avisar que no puedo sin tener que llamar por teléfono en horario de atención.

## Criterios de aceptación

**AC-10.1 — Envío en ventana**
> **Dado que** un turno está a 24 horas con una tolerancia de ±10 minutos,
> **cuando** corre la tarea programada,
> **entonces** se envía la plantilla interactiva con los botones Confirmar, Re-agendar y Cancelar.

**AC-10.2 — Confirmación**
> **Dado que** recibí el recordatorio,
> **cuando** toco "Confirmar",
> **entonces** el turno pasa a estado `confirmed` y recibo un acuse en el chat.

**AC-10.3 — Cancelación libera el horario**
> **Dado que** recibí el recordatorio,
> **cuando** toco "Cancelar",
> **entonces** el evento se elimina de Google Calendar, el horario vuelve a estar disponible y recibo la confirmación de la cancelación.

**AC-10.4 — Sin duplicados ni envíos tardíos**
> **Dado que** un turno fue creado con menos de 24 horas de anticipación, o que ya recibió su recordatorio,
> **cuando** corre la tarea programada,
> **entonces** no se envía un recordatorio duplicado ni uno fuera de ventana.

## Requisitos técnicos

* Tarea del `Laravel Scheduler` cada 10 minutos que consulte turnos con `start_time` dentro de la ventana t-24h ± 10 min y `status='scheduled'` (AC-10.1).
* Crear el índice compuesto `bookings(tenant_id, start_time, status)`. ⚠️ **No existe en el DDL**, y es la consulta que corre cada diez minutos sobre la tabla de mayor crecimiento del sistema.
* Insertar la fila en `notification_logs` con `type='reminder_24h'` **antes** de enviar, y usar la unicidad de `(booking_id, type)` como candado contra duplicados (AC-10.4).
* ⚠️ **Trámite externo bloqueante:** plantilla interactiva de tres botones aprobada por Meta. No es esfuerzo de desarrollo sino tiempo de calendario impredecible; hay que iniciarlo antes que la historia.
* Mapear cada respuesta de botón a su efecto: *Confirmar* → `bookings.status='confirmed'` más acuse en el chat (AC-10.2); *Cancelar* → borrar el evento en Google, liberar la franja y pasar a `cancelled` (AC-10.3); *Re-agendar* → reingreso al flujo de [US-11](US-11-reagendar-y-aviso-t2h.md).
* No enviar recordatorio a turnos creados con menos de 24 horas de anticipación (AC-10.4).
* Renderizar fecha y hora en la zona del tenant, nunca en UTC (AC-07.4).
* Actualizar `notification_logs.status` con el resultado real del envío. ⚠️ El enum admite `'failed'` y **ninguna historia define qué pasa después**; ver [US-24](US-24-recordatorio-que-no-salio.md).
* Distinguir el reingreso desde un recordatorio del reingreso desde un chat entrante: son caminos distintos hacia la misma máquina de estados.

## Estimación de complejidad

**M** — La tarea programada y el manejo de botones son acotados. El riesgo de calendario no está en el código sino en la aprobación de plantillas por parte de Meta.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita turnos reales en la base, o sea US-06. |
| **Negociable** | ✅ | La ventana, el copy y la cantidad de botones son discutibles. |
| **Valiosa** | ✅ | **La historia de mayor valor comercial del backlog:** es el número que sostiene el modelo de precios. |
| **Estimable** | ⚠️ | Estimable en esfuerzo de desarrollo, **no en tiempo de calendario**: depende de una aprobación externa de Meta con demora impredecible. |
| **Pequeña** | ⚠️ | Scheduler, plantilla, manejo de botones y transición de estado. En el límite de lo que entra en un sprint. |
| **Testeable** | ✅ | CA-05 más los cuatro criterios; se fuerza la fecha del servidor para probarlo. |

**Veredicto:** cumple 3 de 6.

> **Mejora recomendada:** sacar "solicitar y obtener la aprobación de plantillas de Meta" a un ítem de gestión rastreado por separado, iniciado durante la sección 2. Hoy esa espera queda escondida dentro de la estimación de la historia y es el cuello de botella real del MVP.

## Por qué es urgente (o crítica)

Es el único número del pitch que se traduce directo a plata en la cabeza del dueño. Si el ausentismo no baja, el MRR de USD 80-150 no tiene con qué justificarse y el producto entero necesita otra propuesta de valor.

## Qué esperamos aprender

* Si 24 horas es la ventana correcta.
* Qué proporción cancela con aviso en vez de faltar — que ya es una victoria, porque libera el horario.
* Si el ausentismo baja parejo entre rubros o hay segmentos donde el recordatorio no mueve la aguja.

**Señal de éxito:** en 3 pilotos y sobre al menos 100 turnos, asistencia ≥ 92% y al menos 60% de los recordatorios con interacción.

## De qué depende

* [US-06](US-06-reservar-y-liberar-sync-inversa.md) — turnos reales en la base.
* **Plantillas de mensaje aprobadas por Meta** — trámite externo, iniciar temprano.
* Tarea programada cada 10 minutos (RF-D1).
* Pilotos con volumen real de turnos.
