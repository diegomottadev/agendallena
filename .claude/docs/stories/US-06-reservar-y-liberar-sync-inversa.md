# US-06 · Reservar de verdad, y liberar si cambia

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | 6 de 14 |
| **Estimación** | **L** |
| **Trazabilidad** | RF-B3 · CA-02 |
| **Depende de** | [US-05](US-05-horarios-realmente-libres.md) |
| **Bloquea a** | [US-07](US-07-hora-correcta-timezones.md) · [US-09](US-09-fallback-ante-caidas.md) · [US-10](US-10-recordatorio-t24h.md) · [US-14](US-14-panel-logs-y-estado.md) · [US-18](US-18-colision-de-horario-al-confirmar.md) · [US-22](US-22-reserva-consistente.md) · [US-23](US-23-renovacion-de-canales-watch.md) |

## Historia

> **Como** dueña de PyME que maneja su agenda desde el celular,
> **quiero** que el turno se cree solo en mi calendario y que el horario se libere si yo borro el evento a mano,
> **para** seguir trabajando como siempre sin miedo a que el sistema me pise.

## Criterios de aceptación

**AC-06.1 — Reserva directa**
> **Dado que** el cliente final eligió un horario disponible,
> **cuando** lo confirma en el chat,
> **entonces** se crea el evento en Google Calendar con nombre, teléfono y monto cotizado, y el cliente recibe la confirmación por WhatsApp.

**AC-06.2 — Liberación por borrado manual**
> **Dado que** existe un turno reservado por el sistema,
> **cuando** borro ese evento desde la app de Google Calendar en mi celular,
> **entonces** el horario vuelve a estar disponible para nuevas consultas en menos de dos minutos.

**AC-06.3 — Movimiento manual del evento**
> **Dado que** existe un turno reservado,
> **cuando** arrastro el evento a otro horario desde el calendario,
> **entonces** el sistema refleja el horario nuevo y libera el original.

**AC-06.4 — Flujo completo verificable**
> **Dado que** un cliente completa el flujo desde WhatsApp con un teléfono de prueba,
> **cuando** termina la reserva,
> **entonces** el turno aparece en Google Calendar y el lead queda registrado en Google Sheets.

## Requisitos técnicos

* Crear el evento con `POST /calendars/{calendarId}/events`, volcando nombre, teléfono y monto cotizado, y guardar el `external_event_id` devuelto en `bookings` (AC-06.1).
* Registrar un canal de notificaciones (`POST /events/watch`) por calendario de tenant y exponer el endpoint público que las recibe (AC-06.2).
* Renovar los canales antes de que caduquen: un canal vencido deja de avisar **en silencio**. Ver [US-23](US-23-renovacion-de-canales-watch.md).
* Procesar las notificaciones de forma idempotente y tolerante al desorden, usando `etag` o `updated` del evento para descartar versiones viejas (AC-06.2, AC-06.3).
* Distinguir los cambios hechos por el propio sistema de los hechos a mano, para no entrar en bucle de sincronización.
* Mapear un evento movido a nuevos `start_time`/`end_time` en `bookings` y liberar la franja original (AC-06.3).
* Crear el índice `bookings(tenant_id, external_event_id)` para resolver la notificación entrante sin escanear la tabla. ⚠️ No existe en el DDL.
* Dar de baja los canales al desconectar la integración, para no dejar notificaciones huérfanas apuntando al endpoint.
* ⚠️ **Hueco de consistencia:** la secuencia e2e marca como atómico un bloque de tres sistemas (Google, MySQL, cola de Sheets) que no tiene compensación. Si el `INSERT` falla después de crear el evento, queda un turno fantasma. Ver [US-22](US-22-reserva-consistente.md).
* ⚠️ Sin definir si el respaldo ante notificaciones poco confiables es *polling* periódico; hoy no está estimado.

## Estimación de complejidad

**L** — La creación del evento es directa, pero la sincronización inversa exige un endpoint público de notificaciones, renovación programada de canales de escucha y manejo de eventos duplicados o fuera de orden.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | Requiere US-05 para tener disponibilidad que reservar. |
| **Negociable** | ✅ | El mecanismo de sincronización inversa queda abierto: *watch* de Google o *polling*. |
| **Valiosa** | ✅ | Es el momento donde el producto genera el valor que cobramos. |
| **Estimable** | ⚠️ | La fiabilidad real de las notificaciones *watch* es una incógnita: si resultan poco confiables, hace falta un respaldo por *polling* que hoy no está estimado. |
| **Pequeña** | ❌ | **Son dos historias con riesgo, tecnología y valor distintos:** la reserva directa (AC-06.1) y la sincronización inversa (AC-06.2 y AC-06.3). |
| **Testeable** | ✅ | CA-02 del PRD la cubre punta a punta. |

**Veredicto:** cumple 3 de 6. **Partirla en directa e inversa** permitiría entregar la reserva —que es el valor central— sin esperar a resolver la parte más incierta.

## Por qué es urgente (o crítica)

Es donde CA-02 se prueba punta a punta. La sincronización **inversa** es la parte que casi todos los competidores hacen mal: si el dueño borra un evento a mano y el slot no se libera, el sistema empieza a mentir y el cliente lo desconecta.

## Qué esperamos aprender

* Si las notificaciones *watch* de Google son confiables y puntuales, o si necesitamos *polling* de respaldo.
* Con qué frecuencia real los dueños editan turnos a mano — el dato que dice si esta funcionalidad es crítica o marginal.

## De qué depende

* [US-05](US-05-horarios-realmente-libres.md) — disponibilidad consultable.
* Endpoint público para las notificaciones de Google.
* Renovación programada de los canales de escucha, que caducan.
