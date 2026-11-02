# US-20 · Derivar a una persona, y que la persona se entere

| | |
| :-- | :-- |
| **Sección** | 3 — Convivir con el equipo humano |
| **Prioridad** | Sprint 5 ([slices](../stories-maps/04-slices-y-secuencia.md)) |
| **Estimación** | **S** |
| **Trazabilidad** | **sin RF** · US-12 AC-12.4 · ramas `NoSlotsMsg` y `Pregunta libre` de [`04-flujo-whatsapp-cliente-final.md`](../03-flujos/04-flujo-whatsapp-cliente-final.md) |
| **Origen** | Huecos [H-05](../stories-maps/05-huecos-y-trazabilidad.md#h-05) y [H-16](../stories-maps/05-huecos-y-trazabilidad.md#h-16) del story map |
| **Depende de** | [US-03](US-03-maquina-de-estados-conversacional.md) · [US-12](US-12-cotizador-parametrico.md) |
| **Bloquea a** | — |

## Historia

> **Como** vendedora de la PyME,
> **quiero** enterarme cuando el bot le promete a alguien que una persona lo va a atender,
> **para** atenderlo antes de que se canse de esperar y me entere por un reclamo.

## Criterios de aceptación

**AC-20.1 — La derivación deja rastro**
> **Dado que** el bot deriva una consulta por valor fuera de rango, por falta de disponibilidad o por una pregunta que no entiende,
> **cuando** envía el mensaje de derivación al cliente,
> **entonces** esa conversación queda marcada como derivada, con el motivo y la hora.

**AC-20.2 — Alguien se entera**
> **Dado que** una conversación quedó derivada,
> **cuando** entro al panel,
> **entonces** aparece en una lista de pendientes de atender, ordenada por antigüedad.

**AC-20.3 — El bot no pisa a la persona**
> **Dado que** una conversación quedó derivada,
> **cuando** el cliente vuelve a escribir,
> **entonces** el bot no intenta retomar el flujo automático hasta que alguien la resuelva o venza la ventana de derivación.

**AC-20.4 — Sin derivaciones duplicadas**
> **Dado que** una conversación ya está derivada,
> **cuando** se dispara otra condición de derivación,
> **entonces** no se genera un segundo pendiente ni se le repite el mensaje al cliente.

## Requisitos técnicos

* Registrar la derivación en la conversación con motivo (`out_of_range`, `no_availability`, `unknown_input`) y timestamp, para poder contarlas por causa (AC-20.1, AC-20.4).
* Reusar la llave Redis `pause:tenant:{tenant_id}:phone:{user_phone}` de [US-08](US-08-pausa-intervencion-humana.md) para silenciar el bot al derivar: es el mismo mecanismo, disparado por nosotros en vez de por el operador (AC-20.3).
* Endpoint de listado de derivaciones pendientes, ordenado por antigüedad, con el aislamiento por `tenant_id` del panel (AC-20.2).
* Acción de resolver la derivación que limpie la marca y devuelva la conversación al flujo automático (AC-20.3).
* Encadenar los tres orígenes: `quote_rules` fuera de rango ([US-12](US-12-cotizador-parametrico.md), AC-12.4), disponibilidad vacía ([US-19](US-19-agenda-sin-disponibilidad.md)) y entrada no reconocida ([US-03](US-03-maquina-de-estados-conversacional.md), AC-03.4 tras N intentos fallidos).
* Definir el umbral de intentos fallidos que dispara la derivación por entrada no reconocida. ⚠️ Sin definir; hoy AC-03.4 repite indefinidamente el recordatorio del paso actual.
* ⚠️ **Decisión abierta — canal del aviso.** El panel es pasivo: alguien tiene que abrirlo. Un aviso por WhatsApp al número del dueño sería activo pero consume plantilla de Meta y su aprobación. En el MVP, la lista en el panel es lo mínimo defendible; el aviso activo puede esperar al primer piloto que lo pida.
* ⚠️ Sin definir la ventana de vencimiento de una derivación no atendida ni qué pasa entonces con el cliente.

## Estimación de complejidad

**S** — Una marca, una lista y el reuso de un mecanismo de pausa que ya existe. Lo que la hace valiosa no es el tamaño sino que cierra tres circuitos abiertos con una sola pieza.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Necesita el estado de US-03 y al menos uno de los tres orígenes construido. |
| **Negociable** | ✅ | El canal del aviso, el umbral de intentos y la ventana de vencimiento están todos abiertos. |
| **Valiosa** | ✅ | Sin esto, tres caminos del flujo prometen algo que el sistema no cumple. Es la diferencia entre derivar y abandonar. |
| **Estimable** | ✅ | Alcance chico; las decisiones abiertas no cambian el tamaño. |
| **Pequeña** | ✅ | Uno o dos días. |
| **Testeable** | ✅ | Los cuatro criterios se disparan forzando cada condición desde el chat. |

**Veredicto:** cumple 5 de 6. Es de las mejores del backlog por la misma razón que US-16 y US-17: nació de un hueco acotado y no de un módulo del PRD.

## Por qué es urgente (o crítica)

**Tres caminos distintos del flujo terminan en "un asesor te va a atender" y ninguno le avisa al asesor.** AC-12.4 deriva por valor fuera de rango, la rama `NoSlotsMsg` deriva por agenda llena, y la pregunta libre delega en un takeover humano que solo se activa si el operador casualmente estaba mirando el teléfono.

Desde el lado del cliente, la promesa se hizo. Desde el lado de la PyME, no pasó nada. Es una promesa incumplida por diseño, y en los tres casos se activa con clientes que **querían comprar**: el que consulta un trabajo grande, el que llama el día que está lleno y el que tiene una duda concreta.

## Qué esperamos aprender

* Cuál de los tres motivos deriva más. Si domina la entrada no reconocida, el problema está en el flujo conversacional; si domina el valor fuera de rango, el cotizador está mal calibrado; si domina la agenda llena, hay un problema de capacidad que es una oportunidad comercial.
* Cuánto tarda el equipo en atender una derivación — el dato que dice si el aviso pasivo alcanza o hace falta uno activo.

**Señal de éxito:** ninguna derivación sin atender por más de un día hábil durante los pilotos.

## De qué depende

* [US-03](US-03-maquina-de-estados-conversacional.md) — estado y conteo de intentos fallidos.
* [US-12](US-12-cotizador-parametrico.md) (AC-12.4) y [US-19](US-19-agenda-sin-disponibilidad.md) — los otros dos orígenes.
* Llave de pausa en Redis, compartida con [US-08](US-08-pausa-intervencion-humana.md) y [US-21](US-21-pausar-el-bot-desde-el-panel.md).
* Vista mínima de panel.
