# US-03 · Máquina de estados conversacional

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | 3 de 14 |
| **Estimación** | **L** |
| **Trazabilidad** | RF-A3 · RF-A5 |
| **Depende de** | [US-02](US-02-ingesta-asincrona-rafaga.md) |
| **Bloquea a** | [US-08](US-08-pausa-intervencion-humana.md) · [US-12](US-12-cotizador-parametrico.md) · [US-13](US-13-leads-a-google-sheets.md) · [US-14](US-14-panel-logs-y-estado.md) · [US-16](US-16-primer-contacto-bienvenida.md) · [US-20](US-20-derivar-a-una-persona.md) · [US-21](US-21-pausar-el-bot-desde-el-panel.md) |

## Historia

> **Como** cliente final que consulta por WhatsApp entre otras cosas,
> **quiero** que el bot recuerde exactamente en qué punto quedó nuestra conversación,
> **para** no tener que repetir lo que ya contesté ni quedar trabado sin salida.

## Criterios de aceptación

**AC-03.1 — Retomar tras una pausa larga**
> **Dado que** dejé la conversación en `GATHERING_PARAMS` hace veinte minutos habiendo dado ya mi nombre,
> **cuando** vuelvo a escribir,
> **entonces** el bot retoma en ese mismo estado, conserva los datos capturados y no me los vuelve a pedir.

**AC-03.2 — Botón vencido de un mensaje anterior**
> **Dado que** la conversación avanzó a `SELECTING_SLOT`,
> **cuando** toco un botón de un mensaje anterior que ya no corresponde,
> **entonces** el bot responde con el paso actual y no ejecuta la acción vieja.

**AC-03.3 — Aislamiento entre conversaciones**
> **Dado que** dos clientes distintos escriben al mismo tenant en paralelo,
> **cuando** ambos avanzan por el flujo,
> **entonces** cada conversación mantiene su estado sin interferir con la otra.

**AC-03.4 — Entrada inesperada**
> **Dado que** envío un texto que no corresponde a ninguna transición válida del estado actual,
> **cuando** se procesa,
> **entonces** el bot responde recordando qué se espera en ese paso y el estado no cambia.

## Requisitos técnicos

* Persistir el estado en `conversations.current_state` y `context_data JSON`, con Redis `session:tenant:{tenant_id}:phone:{user_phone}` como caché de lectura durante la ingesta. **Decisión cerrada:** MySQL es la fuente de verdad y Redis es caché, según [`05-estados-flujo-conversacional.md`](../03-flujos/05-estados-flujo-conversacional.md) §1.
* Implementar las **once** transiciones del catálogo de estados, no las cuatro de RF-A3. ⚠️ RF-A3 está desactualizado respecto de los flujos: faltan `CALCULATING_QUOTE`, `SLOT_SELECTED`, `CONFIRMED`, `RESCHEDULED`, `CANCELLED`, `PAUSED_HUMAN` y `ERROR_FALLBACK`.
* Rechazar toda transición no declarada y responder con el paso vigente sin mutar el estado (AC-03.4).
* Sellar cada mensaje interactivo con el estado que lo emitió: el `id` del botón debe transportar estado y versión para poder descartar los vencidos (AC-03.2).
* Tomar un lock por `(tenant_id, user_phone)` durante la transición, para que dos mensajes en ráfaga no la ejecuten dos veces (AC-03.3).
* Renderizar Quick Replies y List Messages dentro de los límites de la API de Meta (RF-A5).
* **TTL de inactividad: 30 minutos — decisión cerrada (T-007).** Había tres números para la misma idea: la matriz de transiciones decía 30 min, AC-03.1 prometía dos horas y AC-11.3 usaba 30 min para otra cosa. **Gana 30 minutos**, que es lo que ya decían dos de los tres documentos. AC-03.1 quedó corregido acá arriba: retomar la conversación conservando los datos vale dentro de esa ventana, no de dos horas.
* ⚠️ **Decisión abierta — dónde se captura el nombre:** `SLOT_SELECTED` según los flujos y la secuencia e2e, `GATHERING_PARAMS` según AC-03.1 y AC-13.1. Si vale la primera, [US-13](US-13-leads-a-google-sheets.md) escribe filas con el nombre vacío.
* Separar el estado de la **conversación** del estado del **turno**: `CONFIRMED`, `RESCHEDULED` y `CANCELLED` ya viven en `bookings.status`.

## Estimación de complejidad

**L** — Cuatro estados con sus transiciones, persistencia, aislamiento por tenant, manejo de entradas inválidas y renderizado de mensajes interactivos (RF-A5). Es el ítem más grande del backlog.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ❌ | **El peor caso del backlog.** Depende de US-02 y bloquea cuatro historias. Es el cuello de botella estructural. |
| **Negociable** | ✅ | Los cuatro estados son un punto de partida; la historia admite descubrir estados intermedios. |
| **Valiosa** | ✅ | "No repetir lo que ya contesté" es valor directo y percibible por el cliente final. |
| **Estimable** | ⚠️ | **Ya no está bloqueada por la persistencia:** los flujos declaran MySQL como fuente de verdad y Redis como caché. Lo que sí cambia la estimación es que el catálogo real tiene **once** estados y no los cuatro de RF-A3. |
| **Pequeña** | ❌ | Debería partirse: la máquina de estados por un lado, el renderizado de mensajes interactivos (RF-A5) por otro. |
| **Testeable** | ✅ | Los cuatro criterios son observables desde el chat. |

**Veredicto:** cumple 3 de 6, y las tres fallas son accionables. La estimación de **L** está calculada sobre cuatro estados; **hay que rehacerla sobre los once del catálogo antes de comprometerla a un sprint.**

## Por qué es urgente (o crítica)

Es el esqueleto sobre el que cuelgan el cotizador, la agenda y los recordatorios. Si el estado se pierde o se corrompe, cada historia posterior hereda el bug y deja de ser testeable de forma aislada. Es además la diferencia visible entre "un bot" y "un formulario molesto".

## Qué esperamos aprender

Si los cuatro estados de RF-A3 (`IDLE`, `GATHERING_PARAMS`, `SELECTING_SLOT`, `BOOKED`) alcanzan para los caminos reales, o si la conversación exige estados intermedios que no anticipamos.

También si conviene separar el estado de la **conversación** del estado del **turno**: el PRD hoy los mezcla — CA-05 habla de un turno `confirmed`, que no es ninguno de los cuatro declarados.

## De qué depende

* [US-02](US-02-ingesta-asincrona-rafaga.md) — mensajes llegando a la cola.
* Tabla `conversations` con `tenant_id` e índice `(tenant_id, user_phone)`.
* ~~Decisión abierta: MySQL o Redis para el estado.~~ **Cerrada:** MySQL persiste, Redis cachea ([`05-estados-flujo-conversacional.md`](../03-flujos/05-estados-flujo-conversacional.md) §1).
* **Decisiones abiertas:** TTL de inactividad y en qué estado se captura el nombre.
