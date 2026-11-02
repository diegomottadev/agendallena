# T-023 · Lista interactiva de horarios con paginado

| | |
| :-- | :-- |
| **Prioridad** | 23 |
| **Sprint** | 3 |
| **Tipo** | Feature |
| **Historia** | [US-05](../../stories/US-05-horarios-realmente-libres.md) |
| **Story points** | 3 |
| **Depende de** | T-019, T-022 |
| **Bloquea a** | T-026 |
| **Pareto** | **NÚCLEO** · ola 3 · con recorte, ver *Fuera de alcance* |

## Contexto

Meta limita la cantidad de filas por sección de un List Message. Una agenda con disponibilidad amplia genera más horarios que ese límite, y un mensaje que excede el límite **falla el envío entero**: el cliente no recibe nada.

## Alcance

* Renderizado de los horarios disponibles como List Message.
* Paginado con opción de ver más, dentro del límite de la API.
* Agrupación por día para que la lista sea legible.
* Conservación del contexto entre páginas.

## Criterios de aceptación

- [ ] Con más horarios disponibles que el máximo permitido por Meta, se muestran los primeros dentro del límite y se ofrece ver más, **sin que el mensaje falle** *(AC-05.4)*.
- [ ] Pedir la página siguiente no vuelve a preguntar parámetros ni recalcula la cotización.
- [ ] Los horarios se muestran en la zona del tenant, con el día explícito.
- [ ] Seleccionar un ítem resuelve inequívocamente a un horario, sin ambigüedad entre días.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** era el AC-05.4 dentro de una historia que ya tenía tres preocupaciones. Separado, es chico, negociable —la forma del paginado está abierta— y verificable con un calendario vacío que genere muchos slots.

## Fuera de alcance

La consulta de disponibilidad (T-022) y qué hacer cuando la lista viene vacía (T-024).

**Recorte Pareto.** Se difiere el paginado completo con conservación de contexto entre páginas arbitrarias: alcanza con la primera página dentro del límite de Meta y una segunda *(AC-05.4)*. Con turnos de 45 minutos y una ventana de pocos días, la primera página cubre la mayoría de los casos reales. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

La identificación del slot en el `id` del ítem tiene que incluir la fecha completa en UTC: si solo lleva la hora, dos días distintos colisionan.
