# US-22 · Que no queden turnos fantasma en el calendario

| | |
| :-- | :-- |
| **Sección** | 2 — Probar la promesa central |
| **Prioridad** | Sprint 4, pegada a [US-06](US-06-reservar-y-liberar-sync-inversa.md) ([slices](../stories-maps/04-slices-y-secuencia.md)) |
| **Estimación** | **M** |
| **Trazabilidad** | **sin RF** · bloque `critical Agendamiento Atomico` de [`06-secuencia-agenda-cotizacion-e2e.md`](../03-flujos/06-secuencia-agenda-cotizacion-e2e.md) |
| **Origen** | Hueco [H-04](../stories-maps/05-huecos-y-trazabilidad.md#h-04) del story map |
| **Depende de** | [US-06](US-06-reservar-y-liberar-sync-inversa.md) |
| **Bloquea a** | — |

## Historia

> **Como** dueña de PyME,
> **quiero** que nunca quede en mi calendario un evento que el sistema no reconoce,
> **para** no tener horarios bloqueados por turnos que no puedo cancelar, ni recordatorios que no salen.

## Criterios de aceptación

**AC-22.1 — Falla la base después de crear el evento**
> **Dado que** el evento se creó en Google Calendar y la escritura en la base falla,
> **cuando** se resuelve la operación,
> **entonces** el evento no queda huérfano: o se elimina, o queda registrado como pendiente de conciliación y el cliente recibe el fallback en vez de una confirmación.

**AC-22.2 — Nunca se confirma lo que no se guardó**
> **Dado que** cualquier paso del agendamiento falla,
> **cuando** se resuelve el turno de conversación,
> **entonces** el cliente **no** recibe un mensaje de confirmación de reserva.

**AC-22.3 — El reintento no duplica**
> **Dado que** el job de agendamiento se reintenta después de un fallo parcial,
> **cuando** vuelve a ejecutarse,
> **entonces** no se crea un segundo evento en el calendario ni una segunda fila en `bookings`.

**AC-22.4 — La planilla no bloquea la reserva**
> **Dado que** Google Sheets falla al volcar el lead,
> **cuando** se resuelve la reserva,
> **entonces** el turno queda igualmente creado y confirmado, y el volcado se reintenta aparte.

## Requisitos técnicos

* Enviar una clave de idempotencia a Google al crear el evento —usando un `id` de evento derivado determinísticamente de `(tenant_id, conversation_id, start_time)`— para que un reintento no produzca un segundo evento (AC-22.3).
* Verificar la existencia previa del evento antes de crearlo cuando el job se reintenta, y adoptarlo en vez de duplicarlo (AC-22.3).
* Ordenar el envío de la confirmación al cliente **después** de que la fila de `bookings` esté persistida: hoy la secuencia e2e la manda al final pero nada lo garantiza si el paso intermedio falla (AC-22.2).
* Mantener el volcado a Sheets como job independiente, fuera del camino crítico, con su propia cola de reintentos (AC-22.4).
* Job de conciliación periódico que compare los eventos creados por el sistema contra las filas de `bookings` y reporte los desalineados (AC-22.1).
* Marcar el evento como propio para poder identificarlo en la conciliación —`extendedProperties.private` de Google Calendar—, lo que además sirve a [US-06](US-06-reservar-y-liberar-sync-inversa.md) para distinguir sus cambios de los manuales.
* ⚠️ **Decisión abierta — compensar o conciliar.** Borrar el evento al fallar es simple y arriesga borrar algo que la PyME ya vio en su calendario. Dejarlo y conciliarlo después es más seguro y deja una ventana en la que el horario figura ocupado sin turno. La segunda parece preferible con una ventana corta, y no está decidido.
* ⚠️ Ningún requisito del PRD cubre la consistencia entre los tres sistemas. La secuencia e2e etiqueta el bloque como `critical` sin definir qué significa.

## Estimación de complejidad

**M** — La idempotencia y el reordenamiento son acotados. Lo que agrega tamaño es el job de conciliación y decidir la política ante el desalineo, que es una decisión de producto disfrazada de detalle técnico.

## Evaluación INVEST

| Criterio | | Observación |
| :-- | :-: | :-- |
| **Independiente** | ⚠️ | No tiene sentido sin la reserva real de US-06. |
| **Negociable** | ✅ | Compensación o conciliación, y la ventana de tolerancia, están abiertas. |
| **Valiosa** | ⚠️ | Su valor es la **ausencia de un defecto**, igual que US-07. Nadie la pide; se sufre cuando falta. |
| **Estimable** | ✅ | Los modos de falla están enumerados en los criterios. |
| **Pequeña** | ✅ | Entra en un sprint junto con US-06. |
| **Testeable** | ✅ | Se prueba forzando el fallo de la base con el evento ya creado. |

**Veredicto:** cumple 4 de 6.

## Por qué es urgente (o crítica)

El diagrama de secuencia marca `critical Agendamiento Atomico` sobre tres sistemas distintos —Google Calendar, MySQL y la cola de Sheets— **sin ningún mecanismo que lo haga atómico de verdad**. La etiqueta describe una intención, no una garantía.

El modo de falla es particularmente malo porque es silencioso y acumulativo: si el `INSERT` falla después de crear el evento, queda un turno en el calendario del cliente que el sistema no conoce. No genera recordatorio, no se puede cancelar desde el chat, no aparece en el panel, y **bloquea el horario para todos los demás**. La PyME lo descubre cuando alguien no se presenta a un turno que nadie registró.

Es además el tipo de bug que no aparece nunca en una demo y aparece siempre en producción.

## Qué esperamos aprender

* Con qué frecuencia real falla el tramo entre Google y la base — el dato que dice si alcanza con conciliar cada hora o hace falta compensar al instante.
* Si conviene invertir el orden y reservar primero en nuestra base y después en Google, que es la decisión de fondo que esta historia deja abierta.

**Señal de éxito:** cero eventos huérfanos detectados por el job de conciliación durante los pilotos.

## De qué depende

* [US-06](US-06-reservar-y-liberar-sync-inversa.md) — la reserva que hay que hacer consistente.
* [US-09](US-09-fallback-ante-caidas.md) — el mensaje que recibe el cliente cuando la reserva no se completa.
* **Decisión abierta:** compensar o conciliar.
