# T-038 · Registro de asistencia y tasa de ausentismo

| | |
| :-- | :-- |
| **Prioridad** | 38 |
| **Sprint** | 6 |
| **Tipo** | Feature |
| **Historia** | [US-17](../../stories/US-17-registro-de-asistencia.md) |
| **Story points** | 3 |
| **Depende de** | T-009, T-036, T-037 |
| **Bloquea a** | — |
| **Pareto** | **NÚCLEO** · ola 5 · con recorte, ver *Fuera de alcance* |

## Contexto

**Es lo que convierte el MVP en un experimento en lugar de una demo.** Sin este registro construiríamos los recordatorios, los enviaríamos, contaríamos cuántos botones se tocaron — y seguiríamos sin saber si la gente fue. La hipótesis que sostiene el modelo de precios quedaría sin instrumento de medición, y el costo de evitarlo son dos días.

## Alcance

* Marcado de asistencia en un clic desde el panel.
* Agrupación de turnos pasados sin marcar.
* Consulta agregada de tasa por rango de fechas.
* Correlación con la respuesta al recordatorio.

## Criterios de aceptación

- [ ] Un turno que ya pasó su horario se puede marcar como asistió o no asistió con **un solo clic** *(AC-17.1)*.
- [ ] Los turnos pasados sin marcar aparecen agrupados como pendientes, sin tener que buscarlos *(AC-17.2)*.
- [ ] Para un rango de fechas se obtiene cantidad de turnos, asistidos, ausentes y la tasa resultante *(AC-17.3)*.
- [ ] **Para cada turno marcado se sabe si ese cliente había confirmado, cancelado, re-agendado o no había respondido el recordatorio** *(AC-17.4)*.
- [ ] La consulta de pendientes usa el índice de T-009 y no escanea la tabla.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**5 de 6, la mejor del backlog junto con T-021.** Alcance mínimo, cerrado y de máximo apalancamiento: AC-17.3 es directamente el KPI del lean canvas, calculable y comparable.

## Fuera de alcance

El panel de métricas de conversión y ausentismo, que es v2. Acá se produce el dato crudo; medirlo sobre una planilla es aceptable con tres pilotos.

**Recorte Pareto.** Se difiere la correlación con la respuesta al recordatorio *(AC-17.4)*: el argumento comercial solo necesita la tasa, y el análisis de causas llega con volumen. La columna separada de T-036 se construye igual, así que el dato no se pierde y el AC se cumple después sin migración. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ **AC-17.4 es el criterio que más importa.** Sin correlacionar asistencia con respuesta al recordatorio, se sabe cuál es la tasa pero no si el recordatorio la causó — la diferencia entre observar una mejora y poder atribuirla.

⚠️ Si el dueño no marca con disciplina, hay que capturar el dato de otra forma. Mejor descubrirlo en el piloto que al momento de medir.
