# T-037 · Recordatorio interactivo t-24h

| | |
| :-- | :-- |
| **Prioridad** | 37 |
| **Sprint** | 6 |
| **Tipo** | Feature |
| **Historia** | [US-10](../../stories/US-10-recordatorio-t24h.md) |
| **Story points** | 5 |
| **Depende de** | **T-002**, T-009, T-019, T-026 |
| **Bloquea a** | T-039, T-042 |
| **Pareto** | **NÚCLEO** · ola 5 · con recorte, ver *Fuera de alcance* |

## Contexto

**Es el único número del pitch que se traduce directo a plata en la cabeza del dueño.** El lean canvas promete bajar el ausentismo de 20-35% a menos del 8%, y esa es la cifra con la que se cierran ventas. Si no baja, el MRR de USD 80-150 no tiene con qué justificarse.

## Alcance

* Tarea del scheduler cada 10 minutos sobre la ventana t-24h ± 10 min.
* Envío de la plantilla interactiva de tres botones.
* Registro en `notification_logs` como candado contra duplicados.
* Mapeo de cada botón a su efecto.

## Criterios de aceptación

- [ ] Un turno a 24 horas ±10 minutos recibe la plantilla con los botones Confirmar, Re-agendar y Cancelar *(AC-10.1)*.
- [ ] Al tocar Confirmar, el turno pasa a `confirmed` y el cliente recibe acuse en el chat *(AC-10.2)*.
- [ ] Al tocar Cancelar, el evento se elimina de Google Calendar, el horario vuelve a estar disponible y llega la confirmación de cancelación *(AC-10.3)*.
- [ ] Un turno creado con menos de 24 horas de anticipación, o uno que ya recibió su recordatorio, **no** recibe uno duplicado ni fuera de ventana *(AC-10.4)*.
- [ ] El texto muestra la hora en la zona del tenant, nunca en UTC *(AC-07.4)*.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ⚠️ | ⚠️ | ✅ |

**Corrección aplicada:** la falla de *Estimable* de US-10 no era de esfuerzo sino de **tiempo de calendario**: dependía de una aprobación externa con demora impredecible. Se sacó a T-002, que arranca en el sprint 0. Con eso, este ticket es estimable en desarrollo. **S** en ⚠️: scheduler, plantilla, botones y transiciones están en el límite de un sprint.

## Fuera de alcance

Manejo de envíos fallidos (T-039), re-agendamiento (T-042) y aviso t-2h (T-043).

**Recorte Pareto.** Se difiere el efecto del botón Re-agendar, que queda sin acción hasta T-042. **Se agrega:** verificar con un `GET` que el evento sigue existiendo en Calendar antes de enviar el recordatorio — cubre el hueco que deja diferir T-027. ⚠️ La plantilla se aprueba en T-002 y Meta cobra caro cambiarla: **decidir si el botón Re-agendar viaja desde el día 1 antes de mandarla a aprobación.** Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ **No arrancar sin la plantilla aprobada de T-002.** Es el cuello de botella real del MVP y su demora no la controlamos.
