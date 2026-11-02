# T-019 · Renderizado de mensajes interactivos de Meta

| | |
| :-- | :-- |
| **Prioridad** | 19 |
| **Sprint** | 2 |
| **Tipo** | Enabler |
| **Historia** | [US-03](../../stories/US-03-maquina-de-estados-conversacional.md) (RF-A5) |
| **Story points** | 5 |
| **Depende de** | T-018 |
| **Bloquea a** | T-021, T-023, T-037 |
| **Pareto** | **NÚCLEO** · ola 2 · con recorte, ver *Fuera de alcance* |

## Contexto

Todo el flujo avanza a botonazos: bienvenida con opciones, lista de horarios, recordatorio con tres acciones. El renderizado y la interpretación de respuestas interactivas es una preocupación transversal que US-03 mezclaba con la máquina de estados.

## Alcance

* Envío de Quick Reply buttons y de List Messages a través del `MetaAdapter`.
* Codificación en el `id` del botón del estado emisor y su versión.
* Interpretación de la respuesta entrante y su mapeo a una transición.
* Respeto de los límites de la API (cantidad de botones, filas por sección, longitud de textos).

## Criterios de aceptación

- [ ] Avanzada la conversación a `SELECTING_SLOT`, tocar un botón de un mensaje anterior hace que el bot responda con el paso actual y **no** ejecute la acción vieja *(AC-03.2)*.
- [ ] Un mensaje que excede los límites de Meta se recorta o pagina antes de enviarse: nunca falla el envío.
- [ ] La respuesta de un botón se resuelve a una transición sin interpretar texto libre.
- [ ] Los textos que superan el máximo permitido se truncan de forma legible, no a mitad de palabra.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ⚠️ | ✅ | ✅ | ✅ |

**Corrección aplicada:** era la mitad de US-03 que la volvía no *Pequeña*. Como ticket propio es estimable y testeable de forma aislada. **V** en ⚠️ por ser enabler; su valor lo cobran T-021, T-023 y T-037.

## Fuera de alcance

Los contenidos concretos de cada mensaje, que pertenecen a los tickets que los usan.

**Recorte Pareto.** Se difiere el truncado legible de textos que superan el máximo de Meta: los textos son nuestros y de T-017, y se escriben cortos. El sellado del estado emisor en el `id` *(AC-03.2)* y el paginado dentro de los límites **no se recortan**. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ Los límites exactos de la API de Meta (filas por sección, caracteres por botón) hay que confirmarlos contra la documentación vigente al implementar: cambian entre versiones.
