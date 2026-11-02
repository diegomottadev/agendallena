# T-024 · Respuesta cuando no hay disponibilidad

| | |
| :-- | :-- |
| **Prioridad** | 24 |
| **Sprint** | 3 |
| **Tipo** | Feature |
| **Historia** | [US-19](../../stories/US-19-agenda-sin-disponibilidad.md) |
| **Story points** | 3 |
| **Depende de** | T-015, T-017, T-022 |
| **Bloquea a** | — |
| **Pareto** | **NÚCLEO** · ola 3 · con recorte, ver *Fuera de alcance* |

## Contexto

El flujo dibuja esta rama (`HasSlots -- No --> NoSlotsMsg`) y los cuatro criterios de US-05 asumen que **siempre hay algo que ofrecer**. Con la agenda llena —el escenario que más nos conviene comercialmente— el sistema hoy no tiene definido qué contesta.

Es el único punto del recorrido donde el producto puede convertir un "no" en una venta: el cliente que se topa con la agenda llena todavía quiere comprar.

## Alcance

* Distinción de los tres vacíos que hoy se ven idénticos: agenda llena, configuración ausente y error de API.
* Mensaje de agenda llena con opciones por botón.
* Ampliación de la ventana de búsqueda.
* Mensaje diferenciado para día no laborable.

## Criterios de aceptación

- [ ] Sin ningún horario libre en la ventana configurada, llega un mensaje que lo explica y ofrece ampliar la búsqueda o hablar con una persona *(AC-19.1)*.
- [ ] Al elegir ver más adelante, se consulta la ventana siguiente sin repetir los datos ya dados *(AC-19.2)*.
- [ ] Pidiendo turno para un día no laborable, el mensaje distingue "ese día no atendemos" de "ese día está lleno" *(AC-19.3)*.
- [ ] **Un vacío causado por configuración ausente o por error de la API no se comunica como "no hay lugar":** el primero es un bug nuestro y el segundo va al fallback *(AC-19.4)*.
- [ ] Ninguna rama de la consulta de disponibilidad termina sin enviar un mensaje.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**5 de 6.** Nació como hueco acotado del story map, con alcance cerrado. **I** en ⚠️ por depender de la consulta que devuelve vacío.

## Fuera de alcance

La lista de espera y la notificación cuando se libera un horario: v2.

**Recorte Pareto.** Se difieren la ampliación de la ventana de búsqueda *(AC-19.2)* y el mensaje diferenciado de día no laborable *(AC-19.3)*: son ramas de conveniencia, y el mensaje base ya ofrece hablar con una persona *(AC-19.1)*. **La distinción de los tres vacíos *(AC-19.4)* no se recorta:** comunicar un bug nuestro como «no hay lugar» es mentirle al cliente de la PyME. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Notas y riesgos

⚠️ **Sin definir la ventana de búsqueda por defecto ni la ampliada.** Ningún documento dice cuántos días hacia adelante se consultan; hoy sería una constante implícita. Definirlo en este ticket y hacerlo configurable.
