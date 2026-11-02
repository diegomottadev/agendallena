# T-026 · Reserva: creación del evento en Google Calendar

| | |
| :-- | :-- |
| **Prioridad** | 26 |
| **Sprint** | 4 |
| **Tipo** | Feature |
| **Historia** | [US-06](../../stories/US-06-reservar-y-liberar-sync-inversa.md) |
| **Story points** | 5 |
| **Depende de** | T-022, T-023 |
| **Bloquea a** | T-027, T-029, T-030, T-037 |
| **Pareto** | **NÚCLEO** · ola 4 |

## Contexto

**Es el momento donde el producto genera el valor que cobramos.** Todo lo anterior es preparación; acá se cumple CA-02 del PRD punta a punta.

## Alcance

* Captura del nombre en `SLOT_SELECTED` (en el estado que fije T-007).
* Creación del evento vía `POST /events` con nombre, teléfono y monto.
* Persistencia de la fila en `bookings` con el `external_event_id`.
* Mensaje de confirmación al cliente.

## Criterios de aceptación

- [ ] Confirmado el horario en el chat, se crea el evento en Google Calendar con nombre, teléfono y monto cotizado, y el cliente recibe la confirmación por WhatsApp *(AC-06.1)*.
- [ ] La fila de `bookings` queda con `external_event_id`, `start_time` y `end_time` en UTC *(AC-07.1)*.
- [ ] Completado el flujo desde un teléfono de prueba, el turno aparece en Google Calendar *(AC-06.4, CA-02)*.
- [ ] El evento queda marcado como creado por el sistema, de forma recuperable, para poder distinguirlo en T-027 y T-030.
- [ ] La confirmación al cliente se envía **después** de que la fila esté persistida.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ✅ | ✅ | ✅ |

**Corrección aplicada:** US-06 fallaba *Pequeña* porque juntaba dos historias con riesgo, tecnología y valor distintos: la reserva directa y la sincronización inversa. Este ticket toma la directa —el valor central— y **se puede entregar sin esperar a resolver la parte más incierta**, que es T-027.

## Fuera de alcance

Sincronización inversa (T-027), colisión (T-029), consistencia ante fallo parcial (T-030) y volcado a Sheets (T-045).

## Notas y riesgos

⚠️ Depende de la decisión de T-007 sobre en qué estado se captura el nombre. Los flujos dicen `SLOT_SELECTED`; AC-03.1 y AC-13.1 dicen `GATHERING_PARAMS`.
