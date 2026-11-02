# US-11 · Re-agendar sin volver a empezar, y el segundo aviso

| | |
| :-- | :-- |
| **Sección** | 4 — Demostrar el ROI que vendemos |
| **Prioridad** | 11 de 14 |
| **Estimación** | **L** (parcialmente no estimable: el t-2h no tiene RF) |
| **Trazabilidad** | RF-D2 · **el aviso t-2h no tiene RF ni CA** |
| **Depende de** | [US-10](US-10-recordatorio-t24h.md) · [US-05](US-05-horarios-realmente-libres.md) |
| **Bloquea a** | — |

## Historia

> **Como** cliente final que no va a poder llegar a su turno,
> **quiero** re-agendarlo desde el mismo recordatorio,
> **para** no perderlo ni tener que llamar por teléfono en horario de atención.

## Criterios de aceptación

**AC-11.1 — Re-agendar sin repetir datos**
> **Dado que** recibí el recordatorio de 24 horas,
> **cuando** toco "Re-agendar",
> **entonces** recibo la lista de horarios disponibles sin que se me vuelvan a pedir mis datos ni la cotización.

**AC-11.2 — El horario viejo se libera al confirmar el nuevo**
> **Dado que** elegí un horario nuevo,
> **cuando** lo confirmo,
> **entonces** el evento original se elimina del calendario, se crea el nuevo y recibo confirmación con la fecha actualizada.

**AC-11.3 — Abandono no destruye el turno**
> **Dado que** toqué "Re-agendar" y abandoné la conversación sin elegir horario,
> **cuando** pasan 30 minutos,
> **entonces** el turno original sigue vigente y no se perdió por el abandono.

**AC-11.4 — Segundo aviso a t-2h** ⚠️ *pendiente de definición*
> **Dado que** un turno está a 2 horas y no fue confirmado,
> **cuando** corre la tarea programada,
> **entonces** se envía el segundo aviso interactivo.

## Requisitos técnicos

* Reingresar a `SELECTING_SLOT` desde el contexto del recordatorio conservando `context_data`, para no volver a pedir datos ni recalcular la cotización (AC-11.1).
* Crear el evento nuevo **antes** de borrar el anterior: invertir el orden expone una ventana en la que el cliente se queda sin ninguno de los dos (AC-11.2).
* Mantener vigente el turno original mientras el re-agendamiento no se complete, con expiración de 30 minutos por abandono (AC-11.3).
* Reusar el chequeo de colisión de [US-18](US-18-colision-de-horario-al-confirmar.md): el re-agendamiento recorre exactamente el mismo camino de selección y confirmación, y hereda el mismo riesgo de doble reserva.
* Actualizar `bookings.status='rescheduled'` y encadenar el turno nuevo con el anterior para poder auditar la reprogramación. ⚠️ No hay columna que vincule un turno con el que reemplaza.
* Renderizar los horarios nuevos en la zona del tenant (AC-07.4).
* ⚠️ **AC-11.4 no es implementable hoy:** el aviso t-2h no tiene requisito funcional en el PRD. El `notification_logs.type` ya admite `'reminder_2h'` y el C4 nivel 3 dibuja el `Cron t-24h / t-2h`, o sea que la arquitectura lo anticipa y el requisito no existe. **Escribir el RF antes de estimarlo**, y considerar mandarlo a mano durante una semana de piloto para medir si mueve la aguja antes de escribir código.

## Estimación de complejidad

**L** — y con una salvedad: **AC-11.4 no es estimable**. El recordatorio t-2h figura en el alcance del PRD y en el C4 nivel 3 (`Cron t-24h / t-2h`) pero no tiene requisito funcional ni criterio de aceptación. El AC de arriba es una propuesta nuestra, no una definición acordada.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Requiere US-10 y US-05. |
| **Negociable** | ⚠️ | El flujo de re-agendamiento tiene margen; el t-2h no tiene siquiera una definición que negociar. |
| **Valiosa** | ⚠️ | El re-agendamiento tiene valor claro. **El valor del t-2h es una incógnita declarada:** la propia historia dice que no sabemos si aporta por encima del de 24 h. |
| **Estimable** | ❌ | **La mitad de la historia no tiene requisito.** No se estima lo que no está definido. |
| **Pequeña** | ❌ | **Son dos historias sin relación funcional:** un flujo conversacional de reprogramación y un segundo disparo del scheduler. Se agruparon por narrativa, no por cohesión. |
| **Testeable** | ⚠️ | AC-11.1 a AC-11.3 son verificables; AC-11.4 no puede validarse contra ninguna definición acordada. |

**Veredicto:** cumple 0 de 6 de forma limpia. **Es la historia en peor estado del backlog.**

> **Mejora recomendada — partir ya:**
> * **US-11a Re-agendar desde el recordatorio.** Valor claro, criterios listos, estimable como **M**.
> * **US-11b Aviso t-2h.** Escribir el RF primero. Y antes de construirlo, considerar probarlo a mano en un piloto: mandar el segundo aviso manualmente durante una semana y medir si mueve la aguja. Si no la mueve, se cae del MVP y no se escribió una línea.

## Por qué es urgente (o crítica)

Convierte una cancelación en una reprogramación: la diferencia entre un horario perdido y uno recuperado. Es el mecanismo por el cual el número de [US-10](US-10-recordatorio-t24h.md) mejora en vez de solo medirse.

## Qué esperamos aprender

* Qué proporción de los que iban a faltar termina reprogramando.
* Si el segundo aviso a 2 horas aporta algo por encima del de 24, o solo agrega ruido y costo de mensajería. **Ese dato decide si el t-2h sobrevive al MVP.**

## De qué depende

* [US-10](US-10-recordatorio-t24h.md) — recordatorio interactivo funcionando.
* [US-05](US-05-horarios-realmente-libres.md) — para volver a ofrecer disponibilidad.
* Reingreso a la máquina de estados desde un recordatorio: camino distinto al del chat entrante.
* **Definición del requisito t-2h** antes de estimar.
