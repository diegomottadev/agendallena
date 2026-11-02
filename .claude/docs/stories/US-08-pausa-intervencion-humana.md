# US-08 · Que el bot se calle cuando habla el dueño

> # 🔴 PREMISA CAÍDA — 2026-08-20
>
> **Esta historia describe algo que la plataforma no permite.** Toda ella se
> apoya en que el operador responda a mano desde la app móvil y que el bot lo
> detecte. **Un número registrado en la Cloud API deja de poder usarse como chat
> humano:** o es bot, o es persona.
>
> No hay app móvil desde la cual responder ese número, así que **no hay nada que
> detectar**. AC-08.1 es imposible tal como está escrito.
>
> **Lo que rige en su lugar:** RF-A4, modelo de dos números. El bot deriva
> diciendo a qué número escribir; las personas atienden ahí. La pausa manual del
> panel (T-025) sobrevive con otra razón: *"lo voy a llamar por teléfono, que el
> bot no le hable encima"*.
>
> Con esto se cierran **T-004** (3 pts, sin ejecutar) y **T-032** (8 pts).


| | |
| :-- | :-- |
| **Sección** | 3 — Convivir con el equipo humano |
| **Prioridad** | 8 de 14 |
| **Estimación** | **L** (no estimable con confianza hasta cerrar el spike) |
| **Trazabilidad** | RF-A4 · CA-03 |
| **Depende de** | [US-03](US-03-maquina-de-estados-conversacional.md) · **spike de detección** |
| **Bloquea a** | [US-09](US-09-fallback-ante-caidas.md) |

## Historia

> **Como** dueña de PyME que a veces atiende personalmente por WhatsApp,
> **quiero** que el bot se calle apenas yo respondo a mano,
> **para** no quedar mal con mi cliente con dos voces hablándole encima.

## Criterios de aceptación

**AC-08.1 — Pausa al detectar respuesta manual**
> **Dado que** respondí manualmente a un cliente desde la app móvil,
> **cuando** ese cliente vuelve a escribir dentro de los 60 minutos,
> **entonces** el bot no responde y el mensaje queda registrado para que lo vea yo.

**AC-08.2 — Reanudación automática**
> **Dado que** pasaron más de 60 minutos desde mi última respuesta manual,
> **cuando** el cliente escribe de nuevo,
> **entonces** el bot retoma la conversación automáticamente desde el estado en que quedó.

**AC-08.3 — Pausa acotada a la conversación**
> **Dado que** estoy en intervención manual con el cliente A,
> **cuando** escribe el cliente B,
> **entonces** el bot atiende a B con normalidad: la pausa no es global del tenant.

**AC-08.4 — Visibilidad en el panel**
> **Dado que** hay una pausa activa,
> **cuando** abro esa conversación en el panel,
> **entonces** aparece marcada como atendida por humano, con el tiempo restante de pausa.

## Requisitos técnicos

* ⚠️ **Spike bloqueante, previo a todo lo demás:** confirmar si Meta entrega por webhook los mensajes salientes escritos a mano desde la app móvil o WhatsApp Web (campo `messages` con `from` del número del negocio, o algún evento equivalente). Si no los entrega, **ningún requisito de abajo es implementable** y la historia se rediseña entera.
* Suscribir la app de Meta a los campos de eventos salientes, además de los entrantes.
* Escribir la llave `pause:tenant:{tenant_id}:phone:{user_phone}` en Redis con TTL de 3600 s al detectar el mensaje manual (AC-08.1).
* Consultar esa llave en el `HumanDetector` antes de cualquier respuesta automática, y refrescar el TTL con cada nuevo mensaje manual (AC-08.1, AC-08.3).
* Conservar `conversations.current_state` intacto durante la pausa, para retomar exactamente donde quedó al expirar (AC-08.2).
* Persistir el mensaje entrante recibido durante la pausa aunque el bot no responda, para que el operador lo vea (AC-08.1). ⚠️ No hay tabla `messages`.
* Exponer el TTL restante de la llave al panel (AC-08.4).
* Hacer configurable el valor de 60 minutos por tenant. ⚠️ RF-A4 lo fija duro y no hay tabla de configuración donde alojarlo.
* ⚠️ **Hueco arquitectónico:** el C4 nivel 3 modela el componente que **lee** la pausa (`HumanDetector`) y ninguno que la **escriba**. La ruta alternativa, si el spike sale mal, es [US-21](US-21-pausar-el-bot-desde-el-panel.md).

## Estimación de complejidad

**L**, con la advertencia de que **la estimación no es confiable todavía**: el mecanismo de detección es una incógnita abierta. Si Meta no entrega los mensajes salientes manuales, el diseño cambia por completo y la estimación con él.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Requiere US-03 y un spike previo. |
| **Negociable** | ⚠️ | Los 60 minutos están fijados por RF-A4; la propia historia sugiere volverlos configurables. |
| **Valiosa** | ✅ | **La objeción número uno en la mesa de venta**, antes que el precio. |
| **Estimable** | ❌ | **No es estimable hoy.** No sabemos si Meta entrega por webhook los mensajes escritos a mano desde la app. No se puede estimar lo que no se sabe si es posible. |
| **Pequeña** | ⚠️ | Acotada si la detección funciona; grande si hay que inventar un mecanismo alternativo. |
| **Testeable** | ✅ | CA-03 del PRD más los cuatro criterios de arriba. |

**Veredicto:** cumple 2 de 6, y la falla en *Estimable* es la más grave del backlog por su combinación de alto valor comercial e incertidumbre técnica sin resolver.

> **Mejora recomendada — spike previo obligatorio, 2 días:** enviar un mensaje a mano desde la app móvil y verificar si llega el evento por webhook. Si llega, US-08 se estima con normalidad. Si no llega, el requisito cambia de forma entera —¿un comando en el chat? ¿un botón en el panel?— y hay que rediseñarlo antes de comprometerlo a un sprint.

## Por qué es urgente (o crítica)

Es lo que decide si el dueño acepta dejar el bot en su número principal o nos exige uno secundario que le parte la operación. Y según nuestro propio C4 nivel 3, tenemos modelado el componente que **lee** la pausa (`HumanDetector`) pero no el que la **escribe**: el hueco arquitectónico es real.

## Qué esperamos aprender

* Si Meta entrega de forma confiable los mensajes salientes manuales — única forma de detectar el takeover sin pedirle al operador que apriete un botón.
* Si 60 minutos es el número correcto o hay que hacerlo configurable.

**Señal de éxito:** ningún piloto pide desactivar el bot ni migrar a un número secundario en dos semanas, con al menos un takeover real por semana y cero mensajes superpuestos.

## De qué depende

* [US-03](US-03-maquina-de-estados-conversacional.md) — conversación con estado.
* Suscripción a eventos de mensajes salientes en Meta.
* Llaves con expiración en Redis por número de teléfono y `tenant_id`.
