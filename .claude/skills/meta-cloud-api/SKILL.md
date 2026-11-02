---
name: meta-cloud-api
description: Use when integrating WhatsApp through Meta Cloud API in AgendaLlena — webhook verification and ingestion, HMAC signature validation, interactive Quick Reply buttons and List Messages, message templates, the 24-hour customer service window, delivery status webhooks, or outbound sends. Triggers on Meta, WhatsApp, Cloud API, webhook, hub.challenge, X-Hub-Signature-256, HMAC, interactive message, quick reply, list message, plantilla, template, HSM, and on tickets T-001, T-002, T-008, T-010, T-011, T-019, T-037, T-039.
---

# Meta Cloud API — integración de AgendaLlena

Canal único del producto. Todo lo que el cliente final ve pasa por acá.

**Solo API oficial.** El PRD excluye explícitamente librerías no oficiales (Puppeteer, Baileys).

## Handshake de verificación — `GET`

Meta llama al endpoint una vez, al registrar la URL en el portal de desarrolladores.

- Llegan `hub.mode`, `hub.verify_token` y `hub.challenge`.
- Se verifica que `hub.mode` sea `subscribe` y que `hub.verify_token` coincida con el configurado.
- Si coincide: responder **`hub.challenge` en texto plano**, con `200`. No JSON, no envuelto en nada.
- Si no coincide: `403`.

Es CA-01 y T-008. Sin esto, Meta no entrega un solo mensaje.

## Ingesta — `POST`

El orden importa y no es negociable:

1. **Leer el cuerpo crudo antes de cualquier parseo.** Laravel normaliza el body; la firma valida sobre el byte exacto que Meta envió, no sobre el JSON reserializado.
2. **Validar `X-Hub-Signature-256`**: HMAC SHA256 del cuerpo crudo con el App Secret, comparado en **tiempo constante** (`hash_equals`, nunca `==`).
3. Firma inválida → `401`, no se encola nada, queda registro del intento.
4. Firma válida → responder **`200` en menos de 200 ms**, antes de procesar.
5. Encolar el payload íntegro. Ninguna lógica de negocio dentro del request.

**Meta reintenta.** Un `200` tardío o un error se traduce en reentrega del mismo mensaje. Deduplicá por el `id` del mensaje antes de procesar el job, o se crean turnos duplicados.

Durante el piloto, mirá el panel de entregas de Meta: reintentos o errores ahí significan que algo del camino de arriba está mal.

## Mensajes interactivos

Es lo que hace posible el KPI de **respuesta < 15 s**: el cliente toca, no escribe.

**Quick Reply buttons** — para confirmar, cancelar, elegir entre pocas opciones.
**List Messages** — para la grilla de horarios disponibles.

⚠️ Los límites exactos de la API (cantidad de botones, filas por sección, longitud de títulos y descripciones) **verificalos contra la documentación vigente de Meta antes de implementar**: cambian, y un mensaje que excede el límite **falla el envío entero**. La regla de diseño es fija aunque los números cambien:

- Nunca dejar que un mensaje falle por exceso: recortar o paginar **antes** de enviar.
- La lista de horarios se pagina con opción de ver más, agrupada por día para que sea legible.
- Los textos que superan el máximo se truncan de forma legible, no a mitad de palabra.

### Sellado de estado en el `id` del botón

El `id` de cada botón o fila lleva codificado **el estado que lo emitió y su versión**.

Sin eso pasa lo siguiente: el cliente scrollea, toca un botón de un mensaje viejo, y el bot ejecuta una acción que ya no corresponde. Con el sellado, el bot detecta que el `id` viene de un estado anterior y responde con el paso actual. Es AC-03.2.

La respuesta de un botón se resuelve a una transición **sin interpretar texto libre**.

## Plantillas y la ventana de 24 horas

- Fuera de la ventana de servicio al cliente de 24 horas **solo se puede enviar una plantilla aprobada**. El recordatorio t-24h siempre cae fuera de la ventana: por eso T-002 existe y arranca el día 1.
- La aprobación la da Meta y **rechaza por criterios de copy que no son evidentes**: prever al menos dos iteraciones.
- El tiempo de calendario es ajeno. No se puntúa en la estimación, se gestiona con la secuencia.
- ⚠️ **Cambiar una plantilla aprobada es caro.** Decidí qué botones lleva antes de mandarla a aprobación, aunque el efecto de alguno se implemente después.

## Rate limit y estados de entrega

- Ante rate limit, reintentar con backoff exponencial. Solo al agotar reintentos se registra el fallo definitivo.
- Los webhooks de estado (`sent`, `delivered`, `read`, `failed`) son la fuente para detectar recordatorios que no salieron (T-039).

## Reglas del proyecto

- La firma HMAC **no se recorta nunca**: es piso de seguridad, no una feature.
- Todo mensaje saliente lleva la hora en la zona del tenant, nunca UTC.
- Ningún `verify_token`, App Secret ni firma aparece en el log.
- ⚠️ **Decisión abierta sin resolver:** no se sabe si Meta entrega por webhook los mensajes salientes escritos a mano desde la app del negocio. De eso dependía el human takeover automático (T-032), que quedó recortado del MVP y sustituido por la pausa manual desde el panel (T-025).
