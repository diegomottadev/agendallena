# US-16 · La primera respuesta del bot

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | 9 de 17 |
| **Estimación** | **S** |
| **Trazabilidad** | **sin RF en el PRD** · RF-A5 (botones) · KPI de respuesta < 15 s |
| **Origen** | Hueco A1.2 detectado en el [story map](../01-producto/04-user-story-map.md) |
| **Depende de** | [US-03](US-03-maquina-de-estados-conversacional.md) · [US-15](US-15-configurar-reglas-del-negocio.md) |
| **Bloquea a** | — |

## Historia

> **Como** cliente final que le escribe a este número por primera vez,
> **quiero** entender enseguida qué puede resolver este chat,
> **para** decidir si sigo por acá o si me conviene llamar por teléfono.

## Criterios de aceptación

**AC-16.1 — Bienvenida al primer contacto**
> **Dado que** nunca le escribí a este número,
> **cuando** envío cualquier mensaje,
> **entonces** recibo en menos de 15 segundos un saludo con el nombre del negocio y las opciones disponibles.

**AC-16.2 — Avance por botones, sin escribir**
> **Dado que** recibí el mensaje de bienvenida con sus opciones,
> **cuando** toco una de ellas,
> **entonces** la conversación avanza al estado correspondiente sin pedirme que escriba nada.

**AC-16.3 — Reinicio desde cualquier punto**
> **Dado que** estoy a mitad de una conversación y me perdí,
> **cuando** escribo la palabra clave de reinicio,
> **entonces** vuelvo al menú inicial y el estado anterior se limpia.

**AC-16.4 — Contacto fuera de horario**
> **Dado que** escribo fuera del horario de atención configurado por el tenant,
> **cuando** recibo la bienvenida,
> **entonces** el mensaje aclara cuándo atienden y me deja igual iniciar una consulta o reservar.

## Requisitos técnicos

* Disparar la transición `IDLE → GATHERING_PARAMS` ante cualquier mensaje entrante sin conversación activa, creando la fila en `conversations` si no existe (AC-16.1).
* Leer el texto de bienvenida y el horario de atención desde la configuración del tenant. ⚠️ **No hay tabla**; depende de la migración de [US-15](US-15-configurar-reglas-del-negocio.md).
* Enviar un mensaje interactivo con Quick Reply buttons cuyo payload transporte la transición destino, para que tocar una opción no requiera interpretar texto (AC-16.2).
* Definir una palabra clave de reinicio que limpie `context_data`, vuelva a `IDLE` y sea reconocida desde cualquier estado (AC-16.3).
* Comparar la hora actual contra el horario de atención **en la zona del tenant** para decidir el texto de fuera de horario, sin bloquear la reserva (AC-16.4).
* Instrumentar el tiempo transcurrido entre la recepción del webhook y el envío de la bienvenida: es la primera vez que el KPI de menos de 15 segundos se puede medir punta a punta y no por tramos (AC-16.1).
* Respetar el límite de tres botones por Quick Reply de Meta al armar el menú.
* ⚠️ **Sin RF en el PRD.** Nació de un hueco del story map; conviene escribirlo junto con el de [US-15](US-15-configurar-reglas-del-negocio.md).

## Estimación de complejidad

**S** — Un estado de entrada, un mensaje con botones y una palabra clave de reinicio. El texto sale de la configuración de [US-15](US-15-configurar-reglas-del-negocio.md).

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Requiere el estado `IDLE` de US-03 y el mensaje configurable de US-15. |
| **Negociable** | ✅ | El copy, la cantidad de opciones y la palabra de reinicio son todos discutibles. |
| **Valiosa** | ✅ | Es el primer mensaje que ve el 100% de los clientes finales y define si siguen. |
| **Estimable** | ✅ | Alcance chico y cerrado. |
| **Pequeña** | ✅ | Entra holgada en un sprint. |
| **Testeable** | ✅ | Se verifica desde un teléfono nuevo, sin historial. |

**Veredicto:** cumple 5 de 6. **La mejor puntuación INVEST del backlog**, precisamente porque nació de un hueco acotado en lugar de un módulo del PRD.

## Por qué es urgente (o crítica)

Es el único mensaje que recibe el 100% de los clientes finales, y no está definido en ningún lado. US-03 declara el estado `IDLE` pero nadie especificó qué se contesta en él. Es además **la primera vez que el KPI de "respuesta en menos de 15 segundos" se puede medir de punta a punta**: hasta acá lo medíamos por tramos.

## Qué esperamos aprender

* Qué proporción abandona justo después de la bienvenida. Si es alta, el problema está en el copy o en las opciones, no en el resto del flujo.
* Cuál opción se elige más — insumo directo para simplificar el menú y acortar el camino a la reserva.
* Si la gente usa el reinicio, que sería señal de que el flujo confunde en algún punto identificable.

## De qué depende

* [US-03](US-03-maquina-de-estados-conversacional.md) — estado `IDLE` y transiciones.
* [US-15](US-15-configurar-reglas-del-negocio.md) — AC-15.5, texto de bienvenida editable, y horarios para AC-16.4.
* RF-A5 — botones de respuesta rápida.
