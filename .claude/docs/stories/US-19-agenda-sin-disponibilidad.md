# US-19 · Cuando no hay ningún horario libre

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | Sprint 3, pegada a [US-05](US-05-horarios-realmente-libres.md) ([slices](../stories-maps/04-slices-y-secuencia.md)) |
| **Estimación** | **S** |
| **Trazabilidad** | **sin RF** · rama `HasSlots -- No --> NoSlotsMsg` de [`04-flujo-whatsapp-cliente-final.md`](../03-flujos/04-flujo-whatsapp-cliente-final.md) |
| **Origen** | Hueco [H-03](../stories-maps/05-huecos-y-trazabilidad.md#h-03) del story map |
| **Depende de** | [US-05](US-05-horarios-realmente-libres.md) · [US-15](US-15-configurar-reglas-del-negocio.md) |
| **Bloquea a** | — |

## Historia

> **Como** cliente final que pide un turno un día sin lugar,
> **quiero** que me digan qué opciones tengo en vez de recibir una lista vacía,
> **para** no irme a la competencia justo cuando el negocio está en su mejor momento.

## Criterios de aceptación

**AC-19.1 — Sin lugar en la ventana consultada**
> **Dado que** no hay ningún horario libre en los próximos días configurados,
> **cuando** pido turno,
> **entonces** recibo un mensaje que lo explica y me ofrece una alternativa concreta: ampliar la búsqueda o hablar con una persona.

**AC-19.2 — Ampliar la ventana**
> **Dado que** recibí el aviso de que no hay lugar,
> **cuando** elijo ver más adelante,
> **entonces** se consulta la ventana siguiente y, si hay horarios, se me ofrecen sin repetirme los datos ya dados.

**AC-19.3 — Día no laborable**
> **Dado que** pido turno para un día que el tenant no atiende,
> **cuando** se procesa,
> **entonces** el mensaje distingue "ese día no atendemos" de "ese día está lleno", e indica los días de atención.

**AC-19.4 — El silencio nunca es una opción**
> **Dado que** la consulta de disponibilidad devuelve vacío por cualquier motivo,
> **cuando** se resuelve el turno de conversación,
> **entonces** el cliente recibe siempre un mensaje: ninguna rama termina sin respuesta.

## Requisitos técnicos

* **Distinguir tres vacíos que hoy se ven idénticos desde `freeBusy`:** agenda llena, configuración de horarios ausente o inconsistente, y error de la API. Los tres devuelven cero slots y **cada uno exige un mensaje distinto** — decir "no hay lugar" cuando en realidad falta la configuración del tenant es mentirle al cliente y ocultarnos el bug (AC-19.1, AC-19.3, AC-19.4).
* Derivar el caso "error de la API" al fallback de [US-09](US-09-fallback-ante-caidas.md), no a este mensaje.
* Leer los días laborables y el horario de atención de la configuración del tenant para poder responder AC-19.3. ⚠️ Depende de la tabla que crea [US-15](US-15-configurar-reglas-del-negocio.md).
* Definir la ventana de búsqueda por defecto y la ampliada, ambas configurables por tenant. ⚠️ Ningún documento define cuántos días hacia adelante se consultan; hoy es una constante implícita.
* Enviar Quick Reply buttons con las alternativas (ver más adelante / hablar con una persona) en vez de texto libre, para no obligar al cliente a escribir (AC-19.1, AC-19.2).
* Conservar `context_data` al ampliar la ventana, de modo que la cotización y los parámetros ya dados no se repregunten (AC-19.2).
* Encadenar la opción "hablar con una persona" al circuito de [US-20](US-20-derivar-a-una-persona.md).
* Leer el texto del mensaje de la configuración del tenant, igual que la bienvenida y el fallback (AC-15.5).

## Estimación de complejidad

**S** — Es una rama del flujo con tres mensajes y una segunda consulta. Lo único no trivial es la distinción de los tres vacíos, que es más una decisión de diseño que trabajo.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita la consulta de disponibilidad de US-05 y la configuración de US-15. |
| **Negociable** | ✅ | El copy, la cantidad de opciones y el tamaño de la ventana ampliada están abiertos. |
| **Valiosa** | ✅ | Se activa exactamente en el escenario más favorable del negocio: el día que está lleno. Perder una consulta ahí es perder la venta más fácil. |
| **Estimable** | ✅ | Alcance chico y cerrado. |
| **Pequeña** | ✅ | Uno o dos días de trabajo. |
| **Testeable** | ✅ | Se prueba llenando un calendario de prueba y pidiendo turno. |

**Veredicto:** cumple 5 de 6. La única falla es la dependencia, inevitable en una rama de un flujo existente.

## Por qué es urgente (o crítica)

El flujo dibuja esta rama y ninguna historia la escribe: los cuatro criterios de [US-05](US-05-horarios-realmente-libres.md) asumen que **siempre hay algo que ofrecer**. Con la agenda llena —el escenario que más nos conviene comercialmente— el sistema hoy no tiene definido qué contesta, y lo más probable es que mande una lista vacía o directamente no mande nada.

Es además el único punto del recorrido donde el producto puede convertir un "no" en una venta: el cliente que se topa con la agenda llena todavía quiere comprar.

## Qué esperamos aprender

* Qué proporción de las consultas cae en agenda llena. Si es alta, la conversación tiene que empezar por ofrecer disponibilidad y no por cotizar.
* Si la gente acepta ampliar la ventana o prefiere hablar con una persona — el dato que dice si vale la pena construir lista de espera en v2.

## De qué depende

* [US-05](US-05-horarios-realmente-libres.md) — la consulta que devuelve vacío.
* [US-15](US-15-configurar-reglas-del-negocio.md) — días de atención y textos.
* [US-20](US-20-derivar-a-una-persona.md) — para la opción "hablar con una persona".
