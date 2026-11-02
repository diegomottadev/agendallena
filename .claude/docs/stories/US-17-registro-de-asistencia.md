# US-17 · Registrar si el cliente asistió

| | |
| :-- | :-- |
| **Sección** | 4 — Demostrar el ROI que vendemos |
| **Prioridad** | 13 de 17 — **junto con US-10**, es su instrumento de medición |
| **Estimación** | **S** |
| **Trazabilidad** | **sin RF en el PRD** · instrumenta el KPI de asistencia ≥ 92% |
| **Origen** | Hueco A8.2 detectado en el [story map](../01-producto/04-user-story-map.md) |
| **Depende de** | [US-06](US-06-reservar-y-liberar-sync-inversa.md) · [US-10](US-10-recordatorio-t24h.md) |
| **Bloquea a** | — (pero sin ella, US-10 no se puede evaluar) |

## Historia

> **Como** dueña de PyME que quiere saber si esto sirve,
> **quiero** marcar en un clic si el cliente asistió o no a su turno,
> **para** conocer mi tasa real de ausentismo y ver si los recordatorios la están moviendo.

## Criterios de aceptación

**AC-17.1 — Marcar en un clic**
> **Dado que** un turno ya pasó su horario,
> **cuando** lo abro en el panel,
> **entonces** puedo marcarlo como "asistió" o "no asistió" con un solo clic.

**AC-17.2 — Pendientes visibles**
> **Dado que** hay turnos pasados sin marcar,
> **cuando** entro al panel,
> **entonces** aparecen agrupados como pendientes de marcar, para no tener que buscarlos.

**AC-17.3 — Tasa calculable por período**
> **Dado que** elijo un rango de fechas,
> **cuando** consulto,
> **entonces** obtengo la cantidad de turnos, cuántos asistieron, cuántos no y la tasa de asistencia resultante.

**AC-17.4 — Correlación con el recordatorio**
> **Dado que** un turno fue marcado,
> **cuando** se consulta ese dato,
> **entonces** se sabe si ese cliente había confirmado, cancelado, re-agendado o no había respondido el recordatorio.

## Requisitos técnicos

* ⚠️ **Bloqueante — el enum no admite el dato.** `bookings.status` es `ENUM('scheduled','confirmed','rescheduled','cancelled')`: no existe `attended` ni `no_show`. La historia mejor puntuada del backlog no tiene hoy dónde escribir su resultado.
* **Resolver antes de migrar:** la asistencia debería ser una columna aparte (`attendance ENUM('pending','attended','no_show')`), no un valor más del enum de estado. Un turno puede estar `confirmed` por el recordatorio y terminar en `no_show`; con un solo campo ese dato se pisa, y eso rompe directamente AC-17.4, que es el criterio que la historia declara más importante.
* Endpoint de marcado idempotente desde el panel, que registre quién marcó y cuándo (AC-17.1).
* Consulta de turnos pasados sin marcar, agrupados como pendientes (AC-17.2), sostenida por el índice `bookings(tenant_id, start_time, status)` — el mismo que necesita [US-10](US-10-recordatorio-t24h.md) y que hoy no existe.
* Consulta agregada por rango de fechas que devuelva total de turnos, asistidos, ausentes y tasa resultante (AC-17.3).
* Join con `notification_logs` por `booking_id` para saber si ese cliente había confirmado, cancelado, re-agendado o no había respondido el recordatorio (AC-17.4).
* Vista mínima de panel, que puede anticiparse a [US-14](US-14-panel-logs-y-estado.md) en vez de esperarla.
* ⚠️ **Sin RF en el PRD.** Instrumenta el KPI de asistencia ≥ 92% que sostiene el modelo de precios y no tiene requisito que lo mande construir.
* ⚠️ Sin definir el plan B si el dueño no marca con disciplina: un mensaje al cierre del día por WhatsApp, o importación manual. Conviene descubrirlo en el piloto y no al momento de medir.

## Estimación de complejidad

**S** — Un campo de estado, dos botones y una consulta agregada. Es de las historias más chicas del backlog y de las de mayor apalancamiento.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita turnos reales (US-06) y una vista mínima de panel. |
| **Negociable** | ✅ | Cómo se captura el dato está abierto: panel, un mensaje al dueño por WhatsApp al final del día, o importación manual. |
| **Valiosa** | ✅ | Doble: la PyME conoce su tasa real y nosotros podemos evaluar la hipótesis central del negocio. |
| **Estimable** | ✅ | Alcance mínimo y cerrado. |
| **Pequeña** | ✅ | Un día o dos de trabajo. |
| **Testeable** | ✅ | AC-17.3 es directamente el KPI del lean canvas, calculable y comparable. |

**Veredicto:** cumple 5 de 6. Junto con US-16, la mejor puntuación del backlog.

## Por qué es urgente (o crítica)

**Es lo que convierte el MVP en un experimento en lugar de una demo.** El lean canvas promete bajar el ausentismo de 20-35% a menos del 8%, y [US-10](US-10-recordatorio-t24h.md) se compromete a una asistencia ≥ 92%. Sin este registro construiríamos los recordatorios, los enviaríamos, contaríamos cuántos botones se tocaron — y seguiríamos sin saber si la gente fue.

La hipótesis que sostiene el modelo de precios quedaría sin instrumento de medición. Y el costo de evitarlo es un día de trabajo.

**AC-17.4 es el criterio que más importa:** sin correlacionar asistencia con respuesta al recordatorio, sabés cuál es tu tasa pero no si el recordatorio la causó.

## Qué esperamos aprender

* La tasa de asistencia real, contra el 92% comprometido.
* **Si confirmar el recordatorio predice asistir.** Es la diferencia entre observar una mejora y poder atribuirla: si los que confirman asisten mucho más que los que no responden, el recordatorio funciona; si asisten igual, el efecto vino de otro lado.
* Cuánta disciplina tiene el dueño para marcar. Si no marca, hay que capturar el dato de otra forma — y es mejor descubrirlo en el piloto que al medir.

## De qué depende

* [US-06](US-06-reservar-y-liberar-sync-inversa.md) — turnos reales en la base.
* [US-10](US-10-recordatorio-t24h.md) — recordatorios con los que correlacionar.
* Vista mínima de panel (puede anticiparse a [US-14](US-14-panel-logs-y-estado.md)).
