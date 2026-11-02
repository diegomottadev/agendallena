# T-027 · Sincronización inversa vía *watch notifications*

| | |
| :-- | :-- |
| **Prioridad** | 27 |
| **Sprint** | 4 |
| **Tipo** | Feature |
| **Historia** | [US-06](../../stories/US-06-reservar-y-liberar-sync-inversa.md) |
| **Story points** | 8 |
| **Depende de** | T-026 |
| **Bloquea a** | T-028 |
| **Pareto** | **DIFERIDO** — fuera del MVP recortado |

> **Fuera del núcleo Pareto — DIFERIDO.** La disponibilidad ya se consulta en vivo con `freeBusy` en cada conversación, así que los cambios manuales del calendario se ven igual; el *watch* solo mantiene alineada la tabla `bookings`. ⚠️ El hueco que deja —un recordatorio enviado a un turno borrado a mano— se mitiga con la verificación previa agregada a T-037. Se retoma si el piloto muestra edición manual frecuente: son 8 pts con confianza `B`. Ver [`03-analisis-pareto.md`](../../estimacion-backlog/03-analisis-pareto.md).

## Contexto

**Es la parte que casi todos los competidores hacen mal.** Si el dueño borra un evento a mano desde el celular y el horario no se libera, el sistema empieza a mentir y el cliente lo desconecta. Es el sostén literal de "seguí usando tus herramientas".

## Alcance

* Registro de canales `events.watch` por calendario de tenant.
* Endpoint público receptor de notificaciones, con validación del token del canal.
* Procesamiento idempotente y tolerante al desorden.
* Liberación por borrado y actualización por movimiento del evento.

## Criterios de aceptación

- [ ] Borrado un turno desde la app de Google Calendar del celular, el horario vuelve a estar disponible en menos de dos minutos *(AC-06.2)*.
- [ ] Arrastrado el evento a otro horario, el sistema refleja el nuevo y libera el original *(AC-06.3)*.
- [ ] Una notificación repetida o fuera de orden no revierte un estado más nuevo: se descarta por `etag` o por `updated`.
- [ ] Los cambios que hace el propio sistema no disparan una reacción en cadena.
- [ ] Una notificación con token de canal desconocido se rechaza.

## INVEST

| I | N | V | E | S | T |
| :-: | :-: | :-: | :-: | :-: | :-: |
| ⚠️ | ✅ | ✅ | ⚠️ | ⚠️ | ✅ |

**Corrección aplicada:** separado de T-026, que es lo que permite entregar la reserva sin esperar a esto. **E** y **S** en ⚠️ por la misma causa: la fiabilidad real de las notificaciones de Google es una incógnita, y si resultan poco confiables hace falta un respaldo por *polling* que hoy no está estimado. **Es el ticket con más riesgo de desborde del plan.**

## Fuera de alcance

La renovación de los canales, que es T-028 y **sin la cual esto deja de funcionar a las pocas semanas**.

## Notas y riesgos

⚠️ Si al implementar se ve que las notificaciones se pierden, el respaldo por *polling* es un ticket nuevo de 5 puntos que hoy no está en el plan. Evaluar temprano dentro del sprint.
