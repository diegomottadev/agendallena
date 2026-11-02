---
name: google-calendar
description: Use when integrating Google Calendar or Google Sheets in AgendaLlena — the OAuth 2.0 flow, refresh token handling, freeBusy availability queries, slot grid generation with business hours and buffers, event creation and idempotency, watch notifications and their renewal, or timezone conversion at the API boundary. Triggers on Google Calendar, Google Sheets, OAuth, refresh_token, access_type offline, freeBusy, disponibilidad, slots, buffer, events.insert, watch notifications, sync token, canal watch, and on tickets T-003, T-005, T-012, T-013, T-022, T-023, T-026, T-027, T-028, T-029, T-030, T-031, T-044, T-045.
---

# Google Calendar — integración de AgendaLlena

"Sin cambiar tus herramientas" es la mitad de la propuesta de valor: la PyME sigue usando su Google Calendar tal como lo usa hoy. Todo lo que sigue existe para sostener esa frase.

## OAuth 2.0

- La autorización pide **`access_type=offline`**. Sin eso no llega `refresh_token` y la integración se cae en una hora.
- Si Google no devuelve `refresh_token` en una re-autorización, hace falta forzar `prompt=consent`.
- El parámetro `state` va **firmado y transporta el `tenant_id`**. Un callback sin `state` válido se rechaza: es lo que impide que alguien vincule su calendario a otro tenant.
- `access_token` y `refresh_token` se guardan **encriptados en reposo**. Nunca en texto plano, nunca en logs.
- Cancelar la pantalla de consentimiento deja el indicador en rojo con instrucciones para reintentar, no en un estado ambiguo.

### Renovación

- Al detectar expiración se renueva con el `refresh_token`, de forma transparente para el usuario.
- La operación original se reintenta **exactamente una vez** tras renovar. Nunca en bucle.
- Si Google rota el `refresh_token`, el nuevo reemplaza al anterior encriptado.
- Ante `invalid_grant` la integración pasa a `status='expired'` y **se deja de intentar**. Ese estado es el que el panel muestra y el que dispara el fallback al cliente.

## Disponibilidad — `freeBusy`

El corazón técnico del producto. Ofrecer un horario ocupado es peor que no ofrecer nada.

- `POST /freeBusy` con la ventana en **UTC** y el `timeZone` del tenant.
- Devuelve los bloques ocupados. La grilla de horarios se construye así:
  1. Generar los slots del horario de atención configurado, con la duración de turno del tenant.
  2. Restar los bloques ocupados que devolvió Google.
  3. Aplicar el buffer entre turnos: si un turno termina a las 11:00 y el buffer es de 10 minutos, el primer horario ofrecido es 11:10 o posterior.
  4. Excluir días no laborables.
  5. Convertir a la zona local del tenant **recién al mostrar**.

**Consecuencia importante del diseño:** como `freeBusy` se consulta en vivo en cada conversación, **un bloque que la PyME agrega a mano en su calendario deja de ofrecerse automáticamente**, sin necesidad de sincronización inversa. Eso es lo que permitió diferir T-027 del MVP recortado.

⚠️ La latencia de `freeBusy` contra el presupuesto de RNF-04 (≤ 1,5 s totales) no está medida: la mide T-005. Si no entra, aparece un caché — y con él la necesidad de invalidarlo, que reabre T-027.

## Creación del evento

- El evento se crea con nombre, teléfono y monto cotizado, y queda marcado como creado por el sistema de forma recuperable — hace falta para distinguirlo después en la conciliación.
- **Idempotencia:** generá vos el `id` del evento de forma determinística a partir de la reserva, en vez de dejar que Google lo asigne. Un reintento del job con el mismo `id` no crea un segundo evento. (El formato de `id` que acepta Google es restringido: caracteres `a-v` y `0-9`, con largo mínimo — codificá el identificador propio, no lo pegues crudo.)
- **Orden obligatorio:** persistir la fila en `bookings` con su `external_event_id` **y recién después** confirmarle al cliente. Ante cualquier fallo, el cliente no recibe confirmación de una reserva que no existe.
- Si la escritura en base falla después de crear el evento, el evento no queda huérfano: se elimina o se registra para conciliación, y el cliente recibe el fallback.
- ⚠️ **Decisión abierta:** compensar (borrar el evento al fallar) vs. conciliar después. Sin cerrarla, registrá el desalineado — es barato y no compromete la decisión.

## Colisión de horario

Entre que el cliente ve la lista y toca un ítem pasan segundos reales. Dos personas mirando la misma lista es el caso normal en un negocio con demanda.

- **Mínimo obligatorio:** re-consultar `freeBusy` inmediatamente antes de crear el evento, y tratar el conflicto de Google como camino esperado, no como excepción.
- Si el horario se ocupó, el bot avisa y muestra la lista actualizada **sin volver a pedir los datos**.
- Un doble toque del mismo cliente no genera dos eventos.
- ⚠️ **Decisión abierta:** cuándo se toma la reserva temporal — al enviar la lista bloquea horarios de quien no reserva; al elegir el ítem deja una ventana mínima pero real. El TTL correcto no se conoce hasta el piloto.

## Watch notifications

- Los canales de `watch` **vencen** y hay que renovarlos con una tarea programada antes del vencimiento, o la sincronización inversa deja de funcionar en silencio.
- Una notificación **no dice qué cambió**: solo que algo cambió. Hay que sincronizar con `syncToken` para obtener el delta.
- El endpoint receptor es público: necesita idempotencia y tolerancia al desorden de llegada.
- ⚠️ Está **diferido del MVP recortado** (T-027, T-028). Si vuelve, revisá si hace falta respaldo por *polling*: es lo que puede llevar el ticket de 8 a 13 puntos.

## Zonas horarias

RNF-02 y CA-04. Un turno con una hora de corrimiento liquida la credibilidad del piloto.

- La base guarda **siempre UTC**. La conversión ocurre solo al llamar a Google y al redactar el texto de WhatsApp.
- Tenant en `America/Argentina/Buenos_Aires`, cliente elige las 15:00 → la base guarda `18:00 UTC`.
- Tenant en `America/Bogota`, las 15:00 → `20:00 UTC`.
- `America/Santiago` tiene cambio estacional: la aritmética va con Carbon y zona, nunca sumando horas fijas.
- Fijá la versión de `tzdata` en el pipeline, o el mismo test pasa en una máquina y falla en otra.

## Google Sheets

- Un fallo de Sheets **no puede impedir que el turno quede creado y confirmado**: va fuera del camino crítico.
- ⚠️ El volcado de leads está diferido del MVP recortado (T-044, T-045), y eso **contradice CA-02 tal como está escrito**, que exige el turno en Calendar *y* Sheets. Decisión pendiente en T-007.
