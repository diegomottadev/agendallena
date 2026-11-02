# T-018 · Máquina de estados conversacional: núcleo

| | |
| :-- | :-- |
| **Prioridad** | 18 |
| **Sprint** | 2 |
| **Tipo** | Enabler |
| **Historia** | [US-03](../../stories/US-03-maquina-de-estados-conversacional.md) |
| **Story points** | 8 |
| **Depende de** | T-007, T-010 |
| **Bloquea a** | T-019, T-021, T-025, T-035, T-040 |
| **Pareto** | **NÚCLEO** · ola 2 · con recorte, ver *Fuera de alcance* |

## Contexto

Es el esqueleto sobre el que cuelgan el cotizador, la agenda y los recordatorios. Si el estado se pierde o se corrompe, cada historia posterior hereda el bug y deja de ser testeable de forma aislada.

**Advertencia de estimación:** US-03 estima **L** sobre los cuatro estados de RF-A3. El catálogo real de los flujos tiene **once**. Estos 8 puntos están calculados sobre los once.

## Alcance

* Persistencia en `conversations.current_state` y `context_data`, con Redis `session:` como caché de lectura.
* Las once transiciones del catálogo de `05-estados-flujo-conversacional.md`.
* Lock por `(tenant_id, user_phone)` durante la transición.
* Rechazo de transiciones no declaradas.

## Criterios de aceptación

- [ ] Dejada la conversación en `GATHERING_PARAMS`, al volver a escribir el bot retoma en ese estado y conserva los datos capturados *(AC-03.1, con el TTL que fije T-007)*.
- [ ] Un texto que no corresponde a ninguna transición válida hace que el bot recuerde qué se espera, sin cambiar de estado *(AC-03.4)*.
- [ ] Dos clientes del mismo tenant avanzando en paralelo mantienen estados independientes *(AC-03.3)*.
- [ ] Dos mensajes del mismo cliente en ráfaga no ejecutan la transición dos veces.
- [ ] El estado en Redis y el de MySQL nunca divergen: MySQL es la fuente de verdad.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ❌ | ✅ | ✅ | ⚠️ | ⚠️ | ✅ |

**Corrección aplicada:** US-03 fallaba *Estimable* por la decisión MySQL/Redis, que T-007 cierra, y *Pequeña* por mezclar la máquina con el renderizado de mensajes, que sale a T-019. **I** sigue en ❌ y es estructural: es el cuello de botella del backlog, bloquea cinco tickets. **S** en ⚠️: 8 puntos es el techo de lo aceptable; si al planificar se ve más grande, partir por grupos de estados.

## Fuera de alcance

Renderizado de botones y listas (T-019). Los estados de turno `CONFIRMED`, `RESCHEDULED` y `CANCELLED`, que viven en `bookings.status`.

**Recorte Pareto.** Se construyen los estados del camino de reserva —entrada, selección de horario, confirmación, reservado, pausado y fallback— y se difieren los de cotización y derivación: sus consumidores (T-041 y T-035) están fuera del núcleo, y construir un estado sin consumidor es exactamente «por las dudas». ⚠️ Los 8 puntos están estimados sobre once estados: **este ticket es el primer candidato a bajar de estimación.** Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ Dos decisiones de T-007 entran directo acá: el TTL de inactividad y en qué estado se captura el nombre. Sin ellas, AC-03.1 no se puede escribir como test.
