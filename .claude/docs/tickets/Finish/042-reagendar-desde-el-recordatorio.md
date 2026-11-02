# T-042 · Re-agendar desde el recordatorio

| | |
| :-- | :-- |
| **Prioridad** | 42 |
| **Sprint** | 7 |
| **Tipo** | Feature |
| **Historia** | [US-11](../../stories/US-11-reagendar-y-aviso-t2h.md) |
| **Story points** | 5 |
| **Depende de** | T-029, T-037 |
| **Bloquea a** | — |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** Confirmar y Cancelar (T-037) ya bajan el ausentismo; re-agendar reingresa al flujo entero por 5 pts. Se retoma midiendo cuántos tocan Cancelar y vuelven a reservar. ⚠️ Su ausencia deja sin efecto el botón Re-agendar de la plantilla de T-037. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

Convierte una cancelación en una reprogramación: la diferencia entre un horario perdido y uno recuperado. **Es el mecanismo por el cual el número de T-037 mejora en vez de solo medirse.**

Va después de T-029 por una razón concreta: el re-agendamiento recorre el mismo camino de selección y confirmación, así que construirlo antes del control de colisión es construir el mismo bug dos veces.

## Alcance

* Reingreso a `SELECTING_SLOT` desde el contexto del recordatorio.
* Conservación de datos y cotización previos.
* Creación del turno nuevo antes de liberar el anterior.
* Vigencia del turno original ante abandono.

## Criterios de aceptación

- [ ] Al tocar Re-agendar, llega la lista de horarios disponibles **sin volver a pedir datos ni cotización** *(AC-11.1)*.
- [ ] Al confirmar el horario nuevo, el evento original se elimina, se crea el nuevo y llega la confirmación con la fecha actualizada *(AC-11.2)*.
- [ ] Abandonando el re-agendamiento sin elegir horario, pasados 30 minutos **el turno original sigue vigente** *(AC-11.3)*.
- [ ] El turno nuevo se crea antes de borrar el viejo: no hay ventana en la que el cliente se quede sin ninguno.
- [ ] El control de colisión de T-029 aplica también en este camino *(AC-18.4)*.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada — la más grande del plan.** US-11 puntúa **0 de 6**, la peor del backlog, porque agrupaba por narrativa dos cosas sin cohesión: un flujo conversacional de reprogramación y un segundo disparo del scheduler sin requisito. Separado el aviso t-2h a T-043, este ticket pasa a **5 de 6**: criterios listos, valor claro y estimable como M.

## Fuera de alcance

El aviso t-2h, que es T-043 y **no tiene requisito funcional**.

## Notas y riesgos

⚠️ No hay columna que vincule un turno con el que reemplaza. Si se quiere auditar la reprogramación, agregarla acá.
